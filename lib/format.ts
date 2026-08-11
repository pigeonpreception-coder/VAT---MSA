export function formatMoney(cents: number, currency = "NAD"): string {
  const currencyCode = currency.toUpperCase();
  const parts = new Intl.NumberFormat("en-NA", {
    style: "currency",
    currency: currencyCode,
    currencyDisplay: currencyCode === "NAD" ? "code" : "symbol",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).formatToParts(cents / 100);

  return parts.map((part) => part.type === "currency" && currencyCode === "NAD" ? "N$" : part.value).join("");
}

export function formatDate(value: string): string {
  const date = new Date(value.includes("T") ? value : `${value}T00:00:00Z`);
  return new Intl.DateTimeFormat("en-NA", { day: "2-digit", month: "short", year: "numeric" }).format(date);
}

export function formatDateTime(value: string): string {
  return new Intl.DateTimeFormat("en-NA", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(value));
}

export function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join("").toUpperCase() || "VM";
}

export function maskInvoiceNumber(value: string): string {
  if (value.length <= 4) return "****";
  return `${value.slice(0, 2)}${"*".repeat(Math.min(8, value.length - 4))}${value.slice(-2)}`;
}
