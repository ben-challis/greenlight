function walk(node, visit) {
  visit(node);

  if (!Array.isArray(node.children)) {
    return;
  }

  for (const child of node.children) {
    walk(child, visit);
  }
}

export function rewriteDocumentationLinks(options = {}) {
  const base = `/${String(options.base ?? '').replace(/^\/+|\/+$/g, '')}`;

  return (tree) => {
    walk(tree, (node) => {
      if (node.type !== 'link' || typeof node.url !== 'string') {
        return;
      }

      if (/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(node.url)) {
        return;
      }

      const match = node.url.match(/^([^?#]+)\.md([?#].*)?$/);

      if (!match) {
        return;
      }

      const sourcePath = match[1];
      const suffix = match[2] ?? '';

      if (sourcePath.startsWith('architecture/')) {
        node.url = `https://github.com/ben-challis/greenlight/blob/main/docs/${sourcePath}.md${suffix}`;
        return;
      }

      const slug = sourcePath.split('/').at(-1);

      node.url = `${base}/docs/${slug}/${suffix}`;
    });
  };
}
