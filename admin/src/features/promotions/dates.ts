/** The API speaks "YYYY-MM-DD HH:MM:SS" in UTC; <input type="datetime-local"> speaks "YYYY-MM-DDTHH:MM". */
export const fromApiDate = (value: string | null | undefined): string => (value ? value.slice(0, 16).replace(' ', 'T') : '')

export const toApiDate = (value: string): string | null => (value ? `${value.replace('T', ' ')}:00` : null)
