"use client";

import { usePathname, useRouter } from "next/navigation";

export function BackButton() {
  const pathname = usePathname();
  const router = useRouter();

  if (pathname === "/") {
    return null;
  }

  function goBack() {
    if (window.history.length > 1) {
      router.back();
      return;
    }

    router.push("/");
  }

  return <button
    type="button"
    onClick={goBack}
    aria-label="Go back to the previous page"
    className="fixed left-4 top-24 z-[60] inline-flex min-h-11 items-center gap-2 rounded-full border border-white/20 bg-slate-950/85 px-3 text-sm font-bold text-white shadow-lg shadow-slate-950/20 backdrop-blur-md transition hover:-translate-x-0.5 hover:bg-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ffce54] sm:left-6 sm:px-4"
  >
    <span aria-hidden="true">←</span>
    <span className="hidden sm:inline">Back</span>
  </button>;
}
