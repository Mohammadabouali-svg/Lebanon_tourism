import { useState, type FormEvent } from 'react'
import { StarInput, StarRating } from '@/components/StarRating'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/Dialog'
import { Field, TextareaField } from '@/components/ui/Field'
import { Pagination } from '@/components/ui/Pagination'
import { Spinner } from '@/components/ui/Spinner'
import { useCurrentUser } from '@/features/auth/hooks'
import { useFilePreview } from '@/hooks/useFilePreview'
import { parseApiError } from '@/lib/api'
import { formatDateTime } from '@/lib/dates'
import type { Review } from '@/lib/types'
import { useCreateReview, useDeleteReview, useTourReviews } from './queries'

export function ReviewsSection({ tourId }: { tourId: number }) {
  const [page, setPage] = useState(1)
  const reviews = useTourReviews(tourId, page)
  const { user } = useCurrentUser()
  const canWrite = user?.role === 'traveler' && user.email_verified

  return (
    <section className="mt-12" aria-labelledby="reviews-heading">
      <h2 id="reviews-heading" className="text-xl font-semibold text-stone-900">Reviews</h2>

      {canWrite && <ReviewForm tourId={tourId} onCreated={() => setPage(1)} />}

      <div className="mt-6">
        {reviews.isPending ? (
          <Spinner />
        ) : reviews.isError ? (
          <Alert tone="error">{parseApiError(reviews.error).message}</Alert>
        ) : reviews.data.data.length === 0 ? (
          <p className="text-sm text-stone-500">No reviews yet. Travelers who booked this tour can share their experience.</p>
        ) : (
          <>
            <ul className="space-y-4">
              {reviews.data.data.map((review) => (
                <ReviewItem key={review.id} review={review} tourId={tourId} />
              ))}
            </ul>
            <Pagination meta={reviews.data.meta} onPageChange={setPage} />
          </>
        )}
      </div>
    </section>
  )
}

function ReviewItem({ review, tourId }: { review: Review; tourId: number }) {
  const [confirming, setConfirming] = useState(false)
  const remove = useDeleteReview(tourId)

  return (
    <li className="rounded-xl border border-stone-200 bg-white p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-3">
          <span className="font-medium text-stone-900">{review.author_name}</span>
          <StarRating value={review.rating} />
        </div>
        <span className="text-xs text-stone-500">{formatDateTime(review.created_at)}</span>
      </div>
      <p className="mt-2 whitespace-pre-line text-sm text-stone-700">{review.comment}</p>
      {review.photo_url && (
        <img src={review.photo_url} alt={`Photo by ${review.author_name ?? 'a traveler'}`} loading="lazy" className="mt-3 max-h-64 rounded-lg object-cover" />
      )}
      {/* `can_delete` is computed by ReviewPolicy on the server for the current user. */}
      {review.can_delete && (
        <div className="mt-3">
          <Button variant="ghost" size="sm" onClick={() => setConfirming(true)}>
            Delete review
          </Button>
          <ConfirmDialog
            open={confirming}
            title="Delete this review?"
            description="The review and its photo are removed permanently."
            confirmLabel="Delete"
            loading={remove.isPending}
            error={remove.isError && <Alert tone="error">{parseApiError(remove.error).message}</Alert>}
            onCancel={() => setConfirming(false)}
            onConfirm={() => remove.mutate(review.id, { onSuccess: () => setConfirming(false) })}
          />
        </div>
      )}
    </li>
  )
}

function ReviewForm({ tourId, onCreated }: { tourId: number; onCreated: () => void }) {
  const [rating, setRating] = useState(0)
  const [comment, setComment] = useState('')
  const { file: photo, previewUrl: preview, setFile: setPhoto } = useFilePreview()
  const create = useCreateReview(tourId)
  const error = create.error ? parseApiError(create.error) : null

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData()
    form.append('rating', String(rating))
    form.append('comment', comment)
    if (photo) form.append('photo', photo)

    create.mutate(form, {
      onSuccess: () => {
        setRating(0)
        setComment('')
        setPhoto(null)
        onCreated()
      },
    })
  }

  if (create.isSuccess) {
    return <Alert tone="success" className="mt-4">Thank you for your review!</Alert>
  }

  return (
    <form onSubmit={submit} className="mt-4 space-y-4 rounded-xl border border-stone-200 bg-white p-4" noValidate>
      <h3 className="font-semibold text-stone-900">Share your experience</h3>
      {/* 403 comes from ReviewPolicy, e.g. "You can only review tours you have booked." */}
      {error && (error.status === 403 || Object.keys(error.fieldErrors).length === 0) && <Alert tone="error">{error.message}</Alert>}
      <div>
        <StarInput value={rating} onChange={setRating} />
        {error?.fieldErrors.rating && <p className="mt-1 text-sm text-red-600">{error.fieldErrors.rating}</p>}
      </div>
      <TextareaField
        label="Your review"
        value={comment}
        onChange={(event) => setComment(event.target.value)}
        hint="At least 10 characters."
        error={error?.fieldErrors.comment}
      />
      <Field label="Photo (optional, JPG/PNG/WebP up to 5 MB)" error={error?.fieldErrors.photo}>
        {({ id, describedBy }) => (
          <input
            id={id}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            aria-describedby={describedBy}
            onChange={(event) => setPhoto(event.target.files?.[0] ?? null)}
            className="block w-full text-sm text-stone-600 file:mr-3 file:rounded-lg file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-sm file:font-medium"
          />
        )}
      </Field>
      {preview && <img src={preview} alt="Selected photo preview" className="max-h-40 rounded-lg" />}
      <Button type="submit" loading={create.isPending}>
        Publish review
      </Button>
    </form>
  )
}
