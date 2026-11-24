import { useEffect, useState } from 'react'

/*
 * A chosen file plus a temporary URL to preview it in an <img>.
 *
 * URL.createObjectURL() keeps the file in memory until revokeObjectURL() is
 * called. The URL is created in the event handler (when the user picks a
 * file) and the effect only cleans up: it revokes the previous URL when a
 * new one replaces it, and the last one when the component unmounts.
 */
export function useFilePreview() {
  const [file, setFileState] = useState<File | null>(null)
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)

  useEffect(() => {
    return () => {
      if (previewUrl) URL.revokeObjectURL(previewUrl)
    }
  }, [previewUrl])

  function setFile(next: File | null) {
    setFileState(next)
    setPreviewUrl(next ? URL.createObjectURL(next) : null)
  }

  return { file, previewUrl, setFile }
}
