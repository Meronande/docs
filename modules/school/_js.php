<script>
function fdAppend(fd, key, val) {
  if (val === null || val === undefined) return;
  if (Array.isArray(val)) { val.forEach(v => fdAppend(fd, key + '[]', v)); }
  else if (typeof val === 'object') { for (const k in val) fdAppend(fd, key + '[' + k + ']', val[k]); }
  else fd.append(key, val);
}
async function postSchool(data) {
  const fd = new FormData();
  for (const k in data) fdAppend(fd, k, data[k]);
  fd.append('csrf_token', App.csrf);
  const res = await fetch('/ajax/school', { method: 'POST', headers: { 'X-CSRF-Token': App.csrf }, body: fd });
  return res.json();
}
</script>
