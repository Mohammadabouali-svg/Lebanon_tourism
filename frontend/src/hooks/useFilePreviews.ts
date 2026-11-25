import { useEffect, useRef, useState } from 'react'

export interface FilePreview {
  file: File
  url: string
}

/*
 * Several chosen files, each with a temporary URL to preview it in an <img>.
 *
 * The multi-file sibling of useFilePreview (which the tour form keeps using).
 * Same memory rule: every URL from URL.createObjectURL() must be revoked.
 * URLs are created and revoked in the event handlers (add / remove / clear),
 * so a file that stays in the list keeps its URL. The ref only exists so the
 * unmount cleanup can see the latest list.
 *
 * `max` is a convenience for the user, not a rule: the server refuses more
 * than three photos whatever this hook does.
 */
export function useFilePreviews(max: number) {
  const [previews, setPreviews] = useState<FilePreview[]>([])
  const latest = useRef<FilePreview[]>([])

  useEffect(() => {
    latest.current = previews
  }, [previews])

  useEffect(() => () => latest.current.forEach((preview) => URL.revokeObjectURL(preview.url)), [])

  /** Adds files up to `max`; returns how many were left out. */
  function add(files: FileList | File[]): number {
    const incoming = Array.from(files)
    const room = Math.max(0, max - previews.length)
    const accepted = incoming.slice(0, room).map((file) => ({ file, url: URL.createObjectURL(file) }))
    setPreviews([...previews, ...accepted])

    return incoming.length - accepted.length
  }

  function remove(index: number) {
    const removed = previews[index]
    if (removed) URL.revokeObjectURL(removed.url)
    setPreviews(previews.filter((_, i) => i !== index))
  }

  function clear() {
    previews.forEach((preview) => URL.revokeObjectURL(preview.url))
    setPreviews([])
  }

  return { previews, files: previews.map((preview) => preview.file), add, remove, clear }
}
