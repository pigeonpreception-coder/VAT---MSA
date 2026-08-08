import type { Metadata } from "next";
import { headers } from "next/headers";
import "./globals.css";

export async function generateMetadata(): Promise<Metadata> {
  const requestHeaders = await headers();
  const host = requestHeaders.get("x-forwarded-host") ?? requestHeaders.get("host") ?? "localhost:3000";
  const protocol = requestHeaders.get("x-forwarded-proto") ?? (host.startsWith("localhost") ? "http" : "https");
  const metadataBase = new URL(`${protocol}://${host}`);
  return {
    metadataBase,
    title: { default: "VAT-MSA | National VAT Transaction Platform", template: "%s | VAT-MSA" },
    description: "Namibia's controlled VAT transaction, certification, reconciliation and audit platform.",
    icons: { icon: "/favicon.svg", shortcut: "/favicon.svg" },
    openGraph: {
      title: "VAT-MSA | VAT transaction control centre",
      description: "Certification, reconciliation and audit for controlled VAT transactions.",
      type: "website",
      images: [{ url: new URL("/og.png", metadataBase).toString(), width: 1536, height: 1024, alt: "VAT-MSA VAT transaction control centre" }],
    },
    twitter: { card: "summary_large_image", title: "VAT-MSA | VAT transaction control centre", description: "Certification, reconciliation and audit for controlled VAT transactions.", images: [new URL("/og.png", metadataBase).toString()] },
  };
}

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
