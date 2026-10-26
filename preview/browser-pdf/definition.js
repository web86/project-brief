const cjk = /[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\u3000-\u303f\uff01-\uff60]/u
export const needsCjk = (document) => cjk.test(JSON.stringify(document))

// pdfmake has no automatic per-glyph fallback. Keep original codepoints and group adjacent runs.
export function textRuns(value) {
  const runs = []
  for (const character of String(value)) {
    const font =
      /\p{Mark}/u.test(character) && runs.length
        ? runs.at(-1).font
        : cjk.test(character)
          ? 'NotoSC'
          : 'DejaVu'
    if (runs.at(-1)?.font === font) runs.at(-1).text += character
    else runs.push({ text: character, font })
  }
  return runs.length ? runs : [{ text: '', font: 'DejaVu' }]
}

export function documentDefinition(document) {
  let bodyId = 0
  const text = (value, options = {}) => ({
    text: textRuns(value),
    ...(!options.headlineLevel ? { id: `body-${bodyId++}` } : {}),
    ...options,
  })
  const content = [
    text(document.title, { style: 'title' }),
    text(`${document.labels.project}: ${document.project.name}`, { style: 'project' }),
    ...(document.project.website
      ? [text(`${document.labels.website}: ${document.project.website}`, { style: 'project' })]
      : []),
    text(`${document.labels.generated}: ${document.generated.date}`, {
      style: 'project',
      margin: [0, 0, 0, 8],
    }),
  ]
  for (const [index, section] of document.sections.entries()) {
    content.push(
      text(`${section.number}. ${section.name}`, {
        style: 'section',
        headlineLevel: 1,
        ...(index && document.presentation.pageBreakBySection ? { pageBreak: 'before' } : {}),
      }),
    )
    for (const task of section.tasks) {
      content.push(text(`${task.number} ${task.title}`, { style: 'task', headlineLevel: 2 }))
      for (const field of task.content.filter((field) => field.block)) {
        content.push(text(field.label, { style: 'label', headlineLevel: 3 }))
        content.push(text(field.value, { margin: [0, 0, 0, 5] }))
      }
      if (task.attachments.length) {
        content.push(text(document.labels.attachments, { style: 'label', headlineLevel: 3 }))
        content.push({
          ul: task.attachments.map((name) => text(name)),
          fontSize: 9,
          margin: [0, 0, 0, 5],
        })
      }
      if (task.metadata) content.push(text(task.metadata, { style: 'metadata' }))
    }
  }
  content.push(text(document.labels.summary, { style: 'summary', headlineLevel: 1 }))
  for (const row of document.summary) {
    content.push(text(`${row.label}: ${row.value}`, { bold: true, margin: [0, 0, 0, 4] }))
    if (row.coverage) content.push(text(row.coverage, { style: 'metadata' }))
  }
  // Keep short heading/first-paragraph groups together in one layout pass. Never make
  // arbitrary user text unbreakable: pdfmake cannot split an oversized unbreakable stack.
  const grouped = []
  for (let index = 0; index < content.length; index++) {
    if (!content[index].headlineLevel) {
      grouped.push(content[index])
      continue
    }
    const group = [content[index]]
    while (content[index + 1]?.headlineLevel) group.push(content[++index])
    if (content[index + 1]) group.push(content[++index])
    const strings = group
      .flatMap((node) =>
        node.text
          ? node.text.map((run) => run.text)
          : node.ul?.flatMap((item) => item.text.map((run) => run.text)) || [],
      )
      .join('')
    if (
      [...strings].length <= 600 &&
      !strings.includes('\t') &&
      (strings.match(/[\n\r\u000b\u000c\u0085\u2028\u2029]/g) || []).length <= 8
    ) {
      const pageBreak = group[0].pageBreak
      for (const node of group) {
        delete node.headlineLevel
        delete node.pageBreak
      }
      grouped.push({ stack: group, unbreakable: true, ...(pageBreak ? { pageBreak } : {}) })
    } else grouped.push(...group)
  }
  return {
    pageSize: 'A4',
    pageMargins: [56.7, 56.7, 56.7, 62.4],
    info: {
      title: document.title,
      author: 'ProjectBrief',
      creator: 'ProjectBrief browser PDF prototype',
    },
    defaultStyle: { font: 'DejaVu', fontSize: 10, color: '#243044', lineHeight: 1.2 },
    styles: {
      title: { fontSize: 20, bold: true, margin: [0, 0, 0, 14] },
      project: { fontSize: 9, margin: [0, 0, 0, 3] },
      section: { fontSize: 14, bold: true, margin: [0, 16, 0, 7] },
      task: { fontSize: 11.5, bold: true, margin: [0, 11, 0, 5] },
      label: { fontSize: 9.5, bold: true, margin: [0, 4, 0, 2] },
      metadata: { fontSize: 8.5, color: '#586174', margin: [0, 5, 0, 8] },
      summary: { fontSize: 12, bold: true, margin: [0, 16, 0, 6] },
    },
    content: grouped,
    footer: (page, pages) => ({
      columns: [
        text(`${document.labels.footer} · ${document.generated.date}`, {
          width: '*',
          id: 'footer',
        }),
        text(`${page} / ${pages}`, { alignment: 'right', width: 50, id: 'page-number' }),
      ],
      margin: [56.7, 16, 56.7, 0],
      fontSize: 7.5,
      color: '#667085',
    }),
    pageBreakBefore: (node, container) =>
      !!node.headlineLevel &&
      !container.getFollowingNodesOnPage().some((following) => following.id?.startsWith('body-')),
    compress: true,
  }
}
