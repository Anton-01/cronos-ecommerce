/** Every pairing of the picked values, in the order of the axes (colour × width × finish). An axis with nothing picked is left out. */
export function cartesian(axes: string[][]): string[][] {
  const used = axes.filter((a) => a.length > 0)
  if (used.length === 0) return []
  return used.reduce<string[][]>((acc, axis) => acc.flatMap((prefix) => axis.map((v) => [...prefix, v])), [[]])
}

/** The same key whatever the order the values were picked in: what ties a row of the preview to the variation the server creates. */
export const comboKey = (ids: string[]): string => [...ids].sort().join('|')

export const MAX_VARIANTS = 200
