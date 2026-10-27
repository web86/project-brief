// Idea discussions only. The project-wide discussion keeps its existing order/cursor.
export function newestComments(comments = []) {
  return comments
    .map((comment, index) => ({ comment, index }))
    .sort(
      (a, b) =>
        (Date.parse(b.comment.createdAt) || 0) - (Date.parse(a.comment.createdAt) || 0) ||
        (b.comment.sequence ?? b.index) - (a.comment.sequence ?? a.index),
    )
    .map(({ comment }) => comment)
}
