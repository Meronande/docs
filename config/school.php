<?php
/**
 * School (LMS) helpers.
 *
 * PHP 7.0-compatible. Loaded after config/auth.php.
 */
if (!defined('APP_BOOT')) {
    define('APP_BOOT', true);
}

/** Teacher profile linked to the logged-in user, or null. */
function school_current_teacher()
{
    static $teacher = null;
    static $loaded = false;
    if (!$loaded) {
        $uid = (int) current_user()['id'];
        $teacher = db_fetch_one('SELECT * FROM school_teachers WHERE user_id = ? AND status = 1', [$uid], 'i');
        $loaded = true;
    }
    return $teacher;
}

/** Subject allocations for a teacher, with class + subject labels. */
function school_teacher_allocations($teacherId)
{
    return db_fetch_all(
        'SELECT a.*, g.name grade_name, sec.name section_name, sub.name subject_name
         FROM school_allocations a
         JOIN grades g ON g.id = a.grade_id
         JOIN sections sec ON sec.id = a.section_id
         JOIN subjects sub ON sub.id = a.subject_id
         WHERE a.teacher_id = ?
         ORDER BY g.sort_order, g.name, sec.name, sub.name',
        [$teacherId], 'i');
}

/** Homeroom assignments for a teacher. */
function school_teacher_homerooms($teacherId)
{
    return db_fetch_all(
        'SELECT h.*, g.name grade_name, sec.name section_name,
                (SELECT COUNT(*) FROM school_students s WHERE s.grade_id = h.grade_id AND s.section_id = h.section_id AND s.status = 1) student_count
         FROM school_homerooms h
         JOIN grades g ON g.id = h.grade_id
         JOIN sections sec ON sec.id = h.section_id
         WHERE h.teacher_id = ?
         ORDER BY g.sort_order, g.name, sec.name',
        [$teacherId], 'i');
}

/** Sum of mark category weights for a subject (should be 100). */
function school_categories_total($subjectId)
{
    return (float) db_fetch_value('SELECT COALESCE(SUM(max_mark), 0) FROM school_mark_categories WHERE subject_id = ?', [$subjectId], 'i');
}

/** "Grade 9 - A" label from grade/section rows or ids. */
function school_class_label($gradeName, $sectionName)
{
    return $gradeName . ' - ' . $sectionName;
}

/** Directory where exam files are stored. */
function school_exam_dir()
{
    $dir = dirname(__DIR__) . '/assets/uploads/exams';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create exam upload directory.');
    }
    return $dir;
}

/**
 * Read an uploaded spreadsheet into rows of strings.
 * Supports .csv natively and .xlsx when PHP's ZipArchive is available.
 * First row is treated as a header and skipped by the caller.
 */
function school_spreadsheet_rows($tmpFile, $origName)
{
    $ext = strtolower(pathinfo((string) $origName, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') {
        return school_csv_rows($tmpFile);
    }
    if ($ext === 'xlsx') {
        return school_xlsx_rows($tmpFile);
    }
    throw new RuntimeException('Please upload a .csv (Excel: File > Save As > CSV) or .xlsx file.');
}

function school_csv_rows($path)
{
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) throw new RuntimeException('Could not read the file.');
    while (($row = fgetcsv($fh)) !== false) {
        $cells = [];
        foreach ($row as $cell) { $cells[] = trim((string) $cell); }
        if ($cells !== ['']) $rows[] = $cells;
    }
    fclose($fh);
    return $rows;
}

/** Minimal .xlsx reader: sharedStrings + first worksheet, cell text only. */
function school_xlsx_rows($path)
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('This server cannot read .xlsx — please save the file as CSV (Excel: File > Save As > CSV).');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Could not open the .xlsx file.');

    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        preg_match_all('/<si>(.*?)<\/si>/s', $ss, $sis);
        foreach ($sis[1] as $si) {
            preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $ts);
            $shared[] = html_entity_decode(implode('', $ts[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) throw new RuntimeException('The .xlsx file has no readable first sheet.');

    $rows = [];
    if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet, $rowMatches)) {
        foreach ($rowMatches[1] as $rowXml) {
            $cells = [];
            if (preg_match_all('/<c[^>]*?(?:\st="(\w+)")?[^>]*>(?:<v>(.*?)<\/v>)?/s', $rowXml, $cMatches, PREG_SET_ORDER)) {
                foreach ($cMatches as $c) {
                    $type = isset($c[1]) ? $c[1] : '';
                    $val = isset($c[2]) ? $c[2] : '';
                    if ($type === 's' && $val !== '' && isset($shared[(int) $val])) {
                        $cells[] = trim($shared[(int) $val]);
                    } else {
                        $cells[] = trim(html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    }
                }
            }
            $rows[] = $cells;
        }
    }
    return $rows;
}

/** Extract plain text from a .docx (returns null when ZipArchive is unavailable). */
function school_docx_text($path)
{
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return null;
    $text = preg_replace('/<\/w:p>/u', "\n", $xml);
    $text = preg_replace('/<[^>]+>/u', '', (string) $text);
    $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = trim(preg_replace('/[ \t]+/u', ' ', $text));
    return $text === '' ? null : mb_substr($text, 0, 5000);
}
