import { PAGE_SIZE } from '@/components/Listing'
import { getProducts, type Listing, type ListingArgs } from './data'

/**
 * The API pages by cursor (an encoded offset), the shop by page number so every page has a shareable, crawlable URL.
 * Page 1 needs no cursor; for the rest the cursor of the previous page is asked first (cached, so it is cheap).
 */
export async function getPage(args: Omit<ListingArgs, 'after' | 'first'>, page: number): Promise<Listing> {
  if (page <= 1) return getProducts({ ...args, first: PAGE_SIZE })
  const skipped = await getProducts({ ...args, first: (page - 1) * PAGE_SIZE })
  if (!skipped.hasNextPage || !skipped.endCursor) return { items: [], total: skipped.total, hasNextPage: false, endCursor: null }
  return getProducts({ ...args, first: PAGE_SIZE, after: skipped.endCursor })
}
