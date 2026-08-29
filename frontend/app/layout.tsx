import type { Metadata } from "next";
import { Outfit } from "next/font/google";
import "./globals.css";

const outfit = Outfit({
  subsets: ["latin"],
  variable: "--font-outfit",
});

export const metadata: Metadata = {
  title: "Text-to-SQL Interface with Guardrails",
  description: "A portfolio-grade natural language to SQL translation interface featuring strict security guardrails, confidence scores, and instant visual execution audits.",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" className={`${outfit.variable} dark`}>
      <body className="antialiased min-h-screen grid-bg relative">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(59,130,246,0.06),transparent_50%)] pointer-events-none" />
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_bottom_left,rgba(139,92,246,0.05),transparent_50%)] pointer-events-none" />
        {children}
      </body>
    </html>
  );
}
