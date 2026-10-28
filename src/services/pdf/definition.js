const cjk = /[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\u3000-\u303f\uff01-\uff60]/u
export const needsCjk = (document) => cjk.test(JSON.stringify(document))

// pdfmake has no automatic per-glyph fallback. Preserve codepoints and group adjacent runs.
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

// Conservative content limits only decide which short groups may stay together.
// Long text always remains in the normal, measured page flow.
export function compactGroup(value, maximum = 650) {
  return (
    [...value].length <= maximum &&
    !value.includes('\t') &&
    (value.match(/\r\n|[\r\n\u000b\u000c\u0085\u2028\u2029]/g) || []).length <= 8
  )
}

export function documentDefinition(document) {
  const brand = document.branding || {},
    accent = brand.color || '#344054'
  let bodyId = 0
  const text = (value, options = {}) => ({
    text: textRuns(value),
    id: `body-${bodyId++}`,
    ...options,
  })
  const inline = (label, value, options = {}) => ({
    text: [
      ...(label ? textRuns(label + ': ').map((run) => ({ ...run, bold: true })) : []),
      ...textRuns(value || ' '),
    ],
    id: `body-${bodyId++}`,
    ...options,
  })
  const content = []
  const hasBrand =
    brand.logoSvg || brand.logo || brand.name || brand.address?.length || brand.contact?.length
  if (hasBrand) {
    content.push({
      columns: [
        ...(brand.logoSvg || brand.logo
          ? [
              {
                ...(brand.logoSvg ? { svg: brand.logoSvg, font: 'DejaVu' } : { image: brand.logo }),
                fit: [86, 28],
                width: 98,
              },
            ]
          : []),
        {
          width: brand.logoSvg || brand.logo ? 400.88 : 498.88,
          stack: [
            ...(brand.name
              ? [text(brand.name, { fontSize: 11.5, bold: true, margin: [0, 0, 0, 2] })]
              : []),
            ...[brand.address || [], brand.contact || []]
              .filter((lines) => lines.length)
              .map((lines) =>
                text(lines.join(' · '), { fontSize: 8.25, color: '#525866', margin: [0, 0, 0, 1] }),
              ),
          ],
        },
      ],
      columnGap: 0,
      margin: [0, 0, 0, 7],
    })
    content.push({
      canvas: [
        { type: 'line', x1: 0, y1: 0, x2: 498.88, y2: 0, lineWidth: 0.5, lineColor: '#cbd0d8' },
      ],
      margin: [0, 0, 0, 7],
    })
  }
  content.push(text(document.title, { style: 'title' }))
  content.push(text(document.project.name, { fontSize: 10, bold: true, margin: [0, 0, 0, 3] }))
  content.push({
    columns: [
      ...(document.project.website
        ? [
            text(document.project.website, {
              width: 291.88,
              link: /^https?:\/\//i.test(document.project.website)
                ? document.project.website
                : undefined,
            }),
          ]
        : []),
      text(`${document.labels.generated}: ${document.generated.date}`, {
        width: document.project.website ? 195 : 498.88,
        alignment: document.project.website ? 'right' : 'left',
      }),
    ],
    columnGap: 12,
    fontSize: 8.25,
    color: '#525866',
    margin: [0, 0, 0, 6],
  })

  for (const [sectionIndex, section] of document.sections.entries()) {
    const heading = text('', {
      style: 'section',
      headlineLevel: 1,
      id: `section-${sectionIndex}`,
      ...(sectionIndex && document.presentation.pageBreakBySection ? { pageBreak: 'before' } : {}),
    })
    heading.text = [
      ...textRuns(`${section.number}. `).map((run) => ({ ...run, color: accent })),
      ...textRuns(section.name),
    ]
    const sectionNodes = []
    for (const [taskIndex, task] of section.tasks.entries()) {
      const nodes = [
        text(`${task.number} ${task.title}`, {
          style: 'task',
          headlineLevel: 2,
          margin: [0, taskIndex ? 8 : 0, 0, 3],
        }),
      ]
      for (const field of task.content.filter((field) => field.block)) {
        const paragraphs = field.value.split(/\r\n|[\r\n\u000b\u000c\u0085\u2028\u2029]/u)
        for (const [index, paragraph] of paragraphs.entries()) {
          nodes.push(
            inline(index === 0 && field.key !== 'description' ? field.label : '', paragraph, {
              margin: [0, 0, 0, index === paragraphs.length - 1 ? 2 : 0],
            }),
          )
        }
      }
      if (task.attachments.length)
        nodes.push(
          inline(document.labels.attachments, task.attachments.join(' · '), {
            fontSize: 8.5,
            margin: [0, 0, 0, 2],
          }),
        )
      if (task.metadata) nodes.push(text(task.metadata, { style: 'metadata' }))
      const original = [
        task.title,
        ...task.content.filter((field) => field.block).map((field) => field.value),
        ...task.attachments,
        task.metadata,
      ].join('\n')
      if (compactGroup(original)) {
        for (const node of nodes) delete node.headlineLevel
        sectionNodes.push({ stack: nodes, unbreakable: true, id: `body-task-${bodyId++}` })
      } else {
        // Attach a bounded first paragraph to the heading, never the rest of a long task.
        if (
          nodes[1] &&
          compactGroup(task.title + nodes[1].text.map((run) => run.text).join(''), 450)
        ) {
          delete nodes[0].headlineLevel
          sectionNodes.push({
            stack: nodes.splice(0, 2),
            unbreakable: true,
            id: `body-task-${bodyId++}`,
          })
        }
        for (const node of nodes) {
          const value = node.text.map((run) => run.text).join('')
          if (!node.headlineLevel && value.trim() && compactGroup(value, 350))
            node.unbreakable = true
        }
        sectionNodes.push(...nodes)
      }
    }
    if (sectionNodes[0]?.unbreakable && compactGroup(section.name, 180)) {
      const pageBreak = heading.pageBreak
      delete heading.pageBreak
      delete heading.headlineLevel
      content.push({
        stack: [heading, sectionNodes.shift()],
        unbreakable: true,
        ...(pageBreak ? { pageBreak } : {}),
      })
    } else content.push(heading)
    content.push(...sectionNodes)
  }

  content.push({
    stack: [
      text(document.labels.summary, { style: 'summary' }),
      {
        table: {
          widths: ['*', 'auto'],
          body: document.summary.map((row) => [
            {
              stack: [
                text(row.label),
                ...(row.coverage
                  ? [text(row.coverage, { fontSize: 8, color: '#525866', margin: [0, 1, 0, 0] })]
                  : []),
              ],
            },
            text(row.value, { bold: true, alignment: 'right' }),
          ]),
        },
        layout: {
          hLineWidth: () => 0,
          vLineWidth: () => 0,
          paddingLeft: () => 0,
          paddingRight: () => 0,
          paddingTop: () => 2,
          paddingBottom: () => 2,
        },
      },
    ],
    unbreakable: true,
  })
  if (brand.business?.length) {
    const leftKeys = ['legalName', 'payee', 'taxId'],
      rightKeys = ['bankName', 'account', 'bic']
    const left = [],
      right = [],
      notes = []
    for (const [index, row] of brand.business.entries()) {
      const target =
        row.key === 'note'
          ? notes
          : leftKeys.includes(row.key)
            ? left
            : rightKeys.includes(row.key)
              ? right
              : index % 2
                ? right
                : left
      target.push(inline(row.label, row.value, { fontSize: 8.5, margin: [0, 0, 0, 3] }))
    }
    const nodes = [
      text(brand.businessTitle, { style: 'summary', headlineLevel: 1 }),
      {
        columns: [
          { width: 240.44, stack: left },
          { width: 240.44, stack: right },
        ],
        columnGap: 18,
        id: `body-${bodyId++}`,
      },
      ...notes,
    ]
    if (compactGroup(brand.business.map((row) => row.label + row.value).join('\n'), 700)) {
      delete nodes[0].headlineLevel
      content.push({ stack: nodes, unbreakable: true })
    } else content.push(...nodes)
  }
  return {
    pageSize: 'A4',
    pageMargins: [48.2, 48.2, 48.2, 55],
    info: {
      title: document.title,
      author: brand.name || (brand.platform === false ? document.project.name : 'ProjectBrief'),
      creator: brand.platform === false ? document.title : 'ProjectBrief browser PDF',
    },
    defaultStyle: { font: 'DejaVu', fontSize: 9.25, color: '#20242b', lineHeight: 1.23 },
    styles: {
      title: { fontSize: 17, bold: true, margin: [0, 0, 0, 4] },
      section: { fontSize: 12.5, bold: true, margin: [0, 12, 0, 5] },
      task: { fontSize: 10.25, bold: true },
      metadata: { fontSize: 8.25, color: '#525866', margin: [0, 1, 0, 0] },
      summary: { fontSize: 12, bold: true, margin: [0, 12, 0, 5] },
    },
    content,
    footer: (page, pages) => ({
      columns: [
        {
          text: [
            ...textRuns(brand.name || document.project.name),
            ...(brand.platform === false
              ? []
              : [
                  ...textRuns(' · '),
                  ...textRuns(document.labels.footer).map((run) => ({
                    ...run,
                    link: brand.platformUrl,
                  })),
                ]),
          ],
          width: 444.88,
          id: 'footer',
        },
        { text: textRuns(`${page} / ${pages}`), alignment: 'right', width: 42, id: 'page-number' },
      ],
      columnGap: 12,
      margin: [48.2, 12, 48.2, 0],
      fontSize: 7.5,
      color: '#656b75',
    }),
    pageBreakBefore: (node, container) => {
      if (!node.headlineLevel) return false
      const body = container
        .getFollowingNodesOnPage()
        .find((following) => following.id?.startsWith('body-'))
      return !body || !!(body.startPosition && body.startPosition.top > 841.89 - 55 - 23)
    },
    compress: true,
  }
}
