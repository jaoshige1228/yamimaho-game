/**
 * @param {string} path
 * @param {RequestInit} [init]
 */
export async function api(path, init = {}) {
  const headers = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...init.headers,
  };

  const res = await fetch(`/api${path}`, { ...init, headers, credentials: 'include' });
  const contentType = res.headers.get('content-type') || '';

  if (!contentType.includes('application/json')) {
    const text = await res.text();
    throw new Error(
      `APIがJSONを返しませんでした（${res.status}）。CloudFrontやNginxの設定を確認してください。`,
    );
  }

  const data = await res.json();
  if (!res.ok) {
    let message = data.message || res.statusText;
    if (data.errors && typeof data.errors === 'object') {
      const first = Object.values(data.errors).flat().find(Boolean);
      if (first) {
        message = first;
      }
    }
    const err = new Error(message);
    err.status = res.status;
    err.data = data;
    throw err;
  }

  return data;
}
