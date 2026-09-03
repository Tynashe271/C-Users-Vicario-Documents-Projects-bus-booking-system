"use client";

import { useEffect, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { clearAccessToken, hasGuestSession, readAccessToken } from "@/lib/auth-token";
import { platform } from "@/lib/platform";

type PortalKind = "passenger" | "admin" | "authenticated";
type SessionUser = { role: string; company_id: number | null };

const passengerRoles = new Set(["passenger", "guest_passenger", "corporate_passenger", "student_passenger", "frequent_traveller"]);
const adminPrefixes = ["/admin", "/operator", "/agent", "/driver", "/terminal", "/parcels"];
const passengerOnlyPrefixes = ["/account", "/bookings", "/compare", "/tracking", "/track", "/help"];
const authenticatedContentPaths = new Set(["/home", "/about", "/about-platform", "/bus-companies", "/bus-company-directory", "/popular-routes", "/destinations", "/popular-destinations", "/offers", "/promotional-offers", "/travel-information", "/faq", "/frequently-asked-questions", "/contact", "/terms", "/terms-and-conditions", "/privacy", "/privacy-policy", "/refunds", "/refund-policy", "/luggage", "/luggage-policy", "/accessibility", "/accessibility-information", "/app", "/mobile-app"]);

export function portalKind(pathname: string): PortalKind {
  if (adminPrefixes.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`))) return "admin";
  if (passengerOnlyPrefixes.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`))) return "passenger";
  if (authenticatedContentPaths.has(pathname)) return "authenticated";
  return "passenger";
}

export function PortalAccessGuard({ children }: { children: React.ReactNode }) {
  const pathname = usePathname(); const router = useRouter(); const [ready, setReady] = useState(pathname === "/" || pathname === "/login");

  useEffect(() => {
    if (pathname === "/" || pathname === "/login" || pathname.startsWith("/login/")) { queueMicrotask(() => setReady(true)); return; }
    const requiredPortal = portalKind(pathname); const token = readAccessToken();
    if (!token && requiredPortal === "authenticated" && hasGuestSession()) { queueMicrotask(() => setReady(true)); return; }
    if (!token) { const loginPath = requiredPortal === "authenticated" ? "/login" : `/login/${requiredPortal}`; router.replace(`${loginPath}?next=${encodeURIComponent(pathname)}`); return; }
    queueMicrotask(() => setReady(false));
    fetch(`${platform.apiUrl}/auth/me`, { headers: { Accept: "application/json", Authorization: `Bearer ${token}` } })
      .then(async (response) => { if (!response.ok) throw new Error("Session expired"); const user = await response.json() as SessionUser; const isPassenger = passengerRoles.has(user.role); const allowed = requiredPortal === "authenticated" || (requiredPortal === "admin" ? !isPassenger : isPassenger); if (!allowed) { router.replace(`/login/${requiredPortal}?next=${encodeURIComponent(pathname)}&reason=wrong-account`); return; } setReady(true); })
      .catch(() => { clearAccessToken(); const loginPath = requiredPortal === "authenticated" ? "/login" : `/login/${requiredPortal}`; router.replace(`${loginPath}?next=${encodeURIComponent(pathname)}&reason=expired`); });
  }, [pathname, router]);

  if (!ready) return <main className="grid min-h-dvh place-items-center bg-[#0c312d] text-white"><div className="text-center"><span className="mx-auto block size-11 animate-spin rounded-full border-4 border-white/20 border-t-[#ffce54]"/><p className="mt-4 text-sm font-bold">Checking portal access…</p></div></main>;
  return children;
}
