"use client";

import { useRouter } from "next/navigation";

export function LandingLoginGate({ children }: { children: React.ReactNode }) {
  const router = useRouter();

  function requireLogin(event: React.MouseEvent<HTMLDivElement>) {
    const link = (event.target as HTMLElement).closest("a");

    if (!link || link.target === "_blank") {
      return;
    }

    const destination = new URL(link.href, window.location.origin);

    if (destination.origin !== window.location.origin || destination.pathname === "/" || destination.pathname.startsWith("/login")) {
      return;
    }

    event.preventDefault();
    const requestedPath = `${destination.pathname}${destination.search}${destination.hash}`;
    router.push(`/login?next=${encodeURIComponent(requestedPath)}`);
  }

  return <div onClickCapture={requireLogin}>{children}</div>;
}
