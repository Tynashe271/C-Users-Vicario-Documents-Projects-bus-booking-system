"use client";

import Link from "next/link";
import { FormEvent, Suspense, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { clearAccessToken, clearGuestSession, startGuestSession, storeAccessToken } from "@/lib/auth-token";
import { platform } from "@/lib/platform";

export type LoginKind = "passenger" | "admin" | "guest";
type LoginResponse = { token?: string; two_factor_required?: boolean; challenge_token?: string; user?: { role: string }; message?: string; errors?: Record<string, string[]> };
const passengerRoles = new Set(["passenger", "guest_passenger", "corporate_passenger", "student_passenger", "frequent_traveller"]);
const demoPassword = "MufambiDemo123!";
const demos = {
  passenger: [{ label: "Passenger", email: "passenger@mufambi.test" }],
  admin: [
    { label: "Super Admin", email: "admin@mufambi.test" },
    { label: "Operator", email: "operator@mufambi.test" },
    { label: "Driver", email: "driver@mufambi.test" },
    { label: "Booking Agent", email: "agent@mufambi.test" },
  ],
};

export default function LoginPage() { return <Suspense fallback={<Loading/>}><LoginExperience/></Suspense>; }
export function DesignatedLogin({ kind }: { kind: LoginKind }) { return <Suspense fallback={<Loading/>}><LoginExperience designatedKind={kind}/></Suspense>; }

function LoginExperience({ designatedKind }: { designatedKind?: LoginKind }) {
  const params = useSearchParams(); const router = useRouter();
  const portal = params.get("portal");
  const kind: LoginKind = designatedKind ?? (portal === "admin" || portal === "guest" ? portal : "passenger");
  const [mode, setMode] = useState<"login" | "register" | "forgot" | "two-factor">("login");
  const [credentials, setCredentials] = useState({ email: demos[kind === "admin" ? "admin" : "passenger"]?.[0]?.email ?? "", password: demoPassword });
  const [challengeToken, setChallengeToken] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(params.get("reason") === "expired" ? "Your session expired. Please sign in again." : "");
  const nextPath = safeNext(params.get("next"), kind === "admin" ? "/admin" : "/home");

  if (!designatedKind) return <Gateway nextPath={safeNext(params.get("next"), "/home")}/>;

  async function authenticate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(""); const form = new FormData(event.currentTarget);
    try {
      if (mode === "forgot") {
        const body = await post("/auth/password/forgot", { email: form.get("email") });
        setError(body.message ?? "If that account exists, a reset link has been sent."); return;
      }
      if (mode === "two-factor") {
        const body = await post("/auth/two-factor-challenge", { challenge_token: challengeToken, code: form.get("code") });
        finish(body); return;
      }
      const registering = mode === "register";
      const payload = registering
        ? { name: form.get("name"), email: form.get("email"), phone: form.get("phone"), password: form.get("password"), password_confirmation: form.get("password_confirmation"), terms_accepted: form.get("terms_accepted"), device_name: "Mufambi Web" }
        : { login: form.get("email"), password: form.get("password"), device_name: "Mufambi Web" };
      const body = await post(`/auth/${registering ? "register" : "login"}`, payload);
      if (body.two_factor_required && body.challenge_token) { setChallengeToken(body.challenge_token); setMode("two-factor"); return; }
      finish(body);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "We could not sign you in."); }
    finally { setBusy(false); }
  }
  async function post(path: string, payload: Record<string, unknown>): Promise<LoginResponse> {
    const response = await fetch(`${platform.apiUrl}${path}`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify(payload) });
    const body = await response.json() as LoginResponse;
    if (!response.ok) throw new Error(Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? "The supplied credentials could not be verified.");
    return body;
  }
  function finish(body: LoginResponse) {
    if (!body.token || !body.user) throw new Error("The login response was incomplete.");
    const passenger = passengerRoles.has(body.user.role);
    if ((kind === "passenger") !== passenger) throw new Error(kind === "admin" ? "Use a staff demo account shown above." : "Use the Admin & Staff login for this account.");
    storeAccessToken(body.token); router.replace(nextPath);
  }
  function switchPortal(next: LoginKind) { clearAccessToken(); clearGuestSession(); router.replace(`/login/${next}?next=${encodeURIComponent(next === "admin" ? "/admin" : "/home")}`); }

  return <main className="min-h-dvh bg-[#071b19] px-5 py-8 text-white lg:grid lg:place-items-center"><div className="mx-auto grid w-full max-w-6xl overflow-hidden rounded-[2rem] bg-white text-slate-950 shadow-2xl lg:grid-cols-[.9fr_1.1fr]">
    <section className="bg-[#0c312d] p-7 text-white sm:p-10 lg:p-12"><Link href="/login" className="text-2xl font-black">Mufambi</Link><p className="mt-10 text-xs font-black uppercase tracking-[.24em] text-[#ffce54]">Choose your portal</p><h1 className="mt-3 text-4xl font-black">One secure entrance.<br/>The right workspace.</h1><div className="mt-8 grid gap-3"><Portal title="Passenger Login" description="Bookings, profile and saved journeys." active={kind === "passenger"} onClick={() => switchPortal("passenger")}/><Portal title="Guest Access" description="Book without creating an account." active={kind === "guest"} onClick={() => switchPortal("guest")}/><Portal title="Admin & Staff" description="Administration and operations portals." active={kind === "admin"} onClick={() => switchPortal("admin")}/></div></section>
    <section className="p-7 sm:p-10 lg:p-12"><p className="text-xs font-black uppercase tracking-[.2em] text-emerald-700">{kind === "admin" ? "Administration portal" : kind === "guest" ? "Guest portal" : "Passenger portal"}</p><h2 className="mt-2 text-3xl font-black">{mode === "register" ? "Create passenger account" : mode === "forgot" ? "Recover password" : mode === "two-factor" ? "Two-factor verification" : kind === "guest" ? "Continue as Guest" : kind === "admin" ? "Admin & Staff Login" : "Passenger Login"}</h2>
      {kind !== "guest" && mode === "login" && <DemoCredentials kind={kind} select={(email) => setCredentials({ email, password: demoPassword })}/>} {error && <p role="status" className="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900">{error}</p>}
      {kind === "guest" ? <div className="mt-8 rounded-3xl bg-sky-50 p-6"><h3 className="text-xl font-black text-sky-950">No credentials required</h3><p className="mt-2 text-sm text-sky-900/70">Search, compare, check out and track a booking as a guest.</p><button onClick={() => { startGuestSession(); router.replace(nextPath); }} className="mt-6 w-full rounded-2xl bg-sky-800 px-6 py-4 font-black text-white">Continue as Guest</button></div>
        : <form onSubmit={authenticate} className="mt-7 grid gap-5">{mode === "register" && <><Field label="Full name" name="name"/><Field label="Phone number" name="phone"/></>}{mode === "two-factor" ? <Field label="Authenticator or recovery code" name="code"/> : <Field label="Email or phone" name="email" type="text" value={mode === "login" ? credentials.email : ""}/>} {(mode === "login" || mode === "register") && <Field label="Password" name="password" type="password" value={mode === "login" ? credentials.password : ""}/>} {mode === "register" && <Field label="Confirm password" name="password_confirmation" type="password"/>}<button disabled={busy} className={`rounded-2xl px-6 py-4 font-black text-white ${kind === "admin" ? "bg-slate-950" : "bg-[#ef5b35]"}`}>{busy ? "Please wait…" : mode === "forgot" ? "Send reset link" : mode === "two-factor" ? "Verify and continue" : mode === "register" ? "Create account" : "Sign in"}</button></form>}
      {kind === "passenger" && mode !== "two-factor" && <div className="mt-5 flex justify-between text-sm font-bold text-emerald-800"><button onClick={() => setMode(mode === "register" ? "login" : "register")}>{mode === "register" ? "Sign in" : "Create account"}</button><button onClick={() => setMode(mode === "forgot" ? "login" : "forgot")}>{mode === "forgot" ? "Back to login" : "Forgot password?"}</button></div>}
      <p className="mt-8 border-t border-slate-200 pt-5 text-xs text-slate-500">Demo accounts are database-backed and restricted to their designated portal.</p>
    </section></div></main>;
}

function DemoCredentials({ kind, select }: { kind: Exclude<LoginKind, "guest">; select: (email: string) => void }) { return <div className="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4"><p className="text-xs font-black uppercase tracking-wider text-emerald-800">Demo credentials</p><div className="mt-3 grid gap-2">{demos[kind].map((demo) => <button type="button" key={demo.email} onClick={() => select(demo.email)} className="flex flex-wrap justify-between gap-2 rounded-xl bg-white p-3 text-left text-xs"><strong>{demo.label}</strong><code>{demo.email}</code></button>)}</div><p className="mt-3 text-xs"><strong>Password:</strong> <code>{demoPassword}</code></p><p className="mt-1 text-[11px] text-emerald-900/60">Select an account to fill the form.</p></div>; }
function Portal({ title, description, active, onClick }: { title: string; description: string; active: boolean; onClick: () => void }) { return <button onClick={onClick} className={`rounded-2xl border p-5 text-left ${active ? "border-[#ffce54] bg-white/10" : "border-white/15"}`}><span className="block font-black">{title}</span><span className="mt-1 block text-sm text-white/60">{description}</span></button>; }
function Field({ label, name, type = "text", value = "" }: { label: string; name: string; type?: string; value?: string }) { return <><label className="grid gap-2 text-sm font-bold text-slate-700">{label}<input key={value} required name={name} type={type} defaultValue={value} className="min-h-14 rounded-2xl border border-slate-300 px-4 outline-none focus:border-emerald-700 focus:ring-4 focus:ring-emerald-700/10"/></label>{name === "password_confirmation" && <label className="flex items-start gap-3 text-sm font-normal text-slate-600"><input required name="terms_accepted" value="1" type="checkbox" className="mt-1 size-4 accent-[#ef5b35]"/><span>I accept the <Link href="/terms" target="_blank" className="font-bold text-emerald-800 underline">platform terms</Link> and <Link href="/privacy" target="_blank" className="font-bold text-emerald-800 underline">privacy policy</Link>.</span></label>}</>; }
function safeNext(value: string | null, fallback: string) { return value?.startsWith("/") && !value.startsWith("//") ? value : fallback; }
function Loading() { return <main className="grid min-h-dvh place-items-center bg-slate-950 text-white">Opening secure login…</main>; }
function Gateway({ nextPath }: { nextPath: string }) { const destination = encodeURIComponent(nextPath); return <main className="grid min-h-dvh place-items-center bg-[#071b19] px-5 py-10"><section className="w-full max-w-5xl rounded-[2rem] bg-white p-8"><h1 className="text-center text-4xl font-black">Choose your login</h1><div className="mt-10 grid gap-5 md:grid-cols-3"><GatewayLink href={`/login/admin?next=${destination}`} title="Admin & Staff Login"/><GatewayLink href={`/login/guest?next=${destination}`} title="Guest Access"/><GatewayLink href={`/login/passenger?next=${destination}`} title="Passenger Login"/></div></section></main>; }
function GatewayLink({ href, title }: { href: string; title: string }) { return <Link href={href} className="flex min-h-40 items-center justify-center rounded-3xl border border-slate-200 p-6 text-center text-xl font-black shadow-sm hover:shadow-xl">{title}</Link>; }
