const base = import.meta.env.BASE_URL.replace(/\/$/, '');

export function sitePath(path = ''): string {
  const normalized = path.replace(/^\/+/, '');

  if (normalized === '') {
    return `${base}/`;
  }

  return `${base}/${normalized}`;
}

export const githubUrl = 'https://github.com/ben-challis/greenlight';
