import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import { TravelAssistant } from "@/components/travel-assistant";
import { PortalSwitcher } from "@/components/portal-switcher";
import { PortalAccessGuard } from "@/components/portal-access-guard";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: {
    default: "Mufambi Bus Booking",
    template: "%s | Mufambi",
  },
  description:
    "Search routes, choose seats, book tickets, and track journeys across Southern Africa.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="min-h-full flex flex-col">
        <PortalAccessGuard>{children}</PortalAccessGuard>
        <PortalSwitcher />
        <TravelAssistant />
      </body>
    </html>
  );
}
