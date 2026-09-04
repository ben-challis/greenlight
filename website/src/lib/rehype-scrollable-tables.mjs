function textContent(node) {
  return node.type === 'text' ? node.value : (node.children ?? []).map(textContent).join('');
}

export function scrollableTables() {
  return (tree) => {
    let heading = 'Documentation';

    const visit = (parent) => {
      parent.children = parent.children.map((node) => {
        if (node.type !== 'element') {
          return node;
        }

        if (/^h[1-6]$/.test(node.tagName)) {
          heading = textContent(node).trim();
        }

        if (node.tagName === 'table') {
          const caption = node.children.find((child) => child.tagName === 'caption');
          return {
            type: 'element',
            tagName: 'div',
            properties: {
              className: ['table-scroll'],
              tabIndex: 0,
              role: 'region',
              ariaLabel: caption ? textContent(caption).trim() : `${heading} table`,
            },
            children: [node],
          };
        }

        if (node.children) {
          visit(node);
        }

        return node;
      });
    };

    visit(tree);
  };
}
