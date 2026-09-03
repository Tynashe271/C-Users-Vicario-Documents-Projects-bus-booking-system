"use client";

import { FormEvent, useState } from "react";
import { platform } from "@/lib/platform";
import { readAccessToken } from "@/lib/auth-token";

const requiredDocuments = ["registration", "operator_licence", "transport_permit", "insurance", "bank_confirmation"] as const;

export default function OperatorApplicationPage() {
  const [companyId, setCompanyId] = useState<number | null>(null);
  const [uploaded, setUploaded] = useState<string[]>([]);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const headers = () => ({ Accept: "application/json", Authorization: `Bearer ${readAccessToken()}` });

  async function createApplication(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setMessage("");
    const form = new FormData(event.currentTarget);
    const response = await fetch(`${platform.apiUrl}/operator-applications`, { method: "POST", headers: { ...headers(), "Content-Type": "application/json" }, body: JSON.stringify({ name: form.get("name"), trading_name: form.get("trading_name"), registration_number: form.get("registration_number"), tax_number: form.get("tax_number"), currency: form.get("currency"), business_address: { line_1: form.get("address"), city: form.get("city"), country: form.get("country") }, contact: { name: form.get("contact_name"), phone: form.get("phone"), email: form.get("email") } }) });
    const body = await response.json(); setBusy(false);
    if (!response.ok) return setMessage(firstError(body));
    setCompanyId(body.id); setMessage("Application created. Upload every required document before submission.");
  }

  async function upload(type: string, file: File | undefined) {
    if (!companyId || !file) return;
    setBusy(true); const data = new FormData(); data.set("type", type); data.set("document", file);
    const response = await fetch(`${platform.apiUrl}/operator-applications/${companyId}/documents`, { method: "POST", headers: headers(), body: data });
    const body = await response.json(); setBusy(false);
    if (!response.ok) return setMessage(firstError(body));
    setUploaded((items) => [...new Set([...items, type])]); setMessage(`${type.replaceAll("_", " ")} uploaded securely.`);
  }

  async function submit() {
    if (!companyId) return;
    setBusy(true); const response = await fetch(`${platform.apiUrl}/operator-applications/${companyId}/submission`, { method: "POST", headers: headers() }); const body = await response.json(); setBusy(false);
    setMessage(response.ok ? "Application submitted for platform review." : firstError(body));
  }

  return <main className="min-h-screen bg-[#edf1ef] text-slate-950"><header className="bg-[#0c312d] px-5 py-12 text-white lg:px-8"><div className="mx-auto max-w-5xl"><p className="text-xs font-black uppercase tracking-[.22em] text-[#ffce54]">Company onboarding</p><h1 className="mt-3 text-4xl font-black md:text-6xl">Join the Mufambi network</h1><p className="mt-4 max-w-2xl text-emerald-50/70">Register the operator, upload compliance documents, and submit one secure application to the Laravel review workflow.</p></div></header><div className="mx-auto grid max-w-5xl gap-6 px-5 py-8 lg:grid-cols-[1.2fr_.8fr] lg:px-8">
    <form onSubmit={createApplication} className="rounded-3xl bg-white p-6 shadow-sm"><h2 className="text-2xl font-black">1. Company details</h2><div className="mt-6 grid gap-4 sm:grid-cols-2"><input required name="name" className="input" placeholder="Registered company name" /><input name="trading_name" className="input" placeholder="Trading name" /><input required name="registration_number" className="input" placeholder="Registration number" /><input required name="tax_number" className="input" placeholder="Tax number" /><input required name="address" className="input sm:col-span-2" placeholder="Business address" /><input required name="city" className="input" placeholder="City" /><input required name="country" className="input" defaultValue="Zimbabwe" /><input required name="contact_name" className="input" placeholder="Contact person" /><input required name="phone" className="input" placeholder="Phone" /><input required name="email" type="email" className="input" placeholder="Business email" /><select name="currency" className="input"><option>USD</option><option>ZWG</option><option>ZAR</option></select></div><button disabled={busy || companyId !== null} className="mt-6 rounded-xl bg-[#ef5b35] px-6 py-4 font-black text-white disabled:opacity-50">Create application</button></form>
    <section className="rounded-3xl bg-white p-6 shadow-sm"><h2 className="text-2xl font-black">2. Compliance documents</h2><div className="mt-5 grid gap-3">{requiredDocuments.map((type) => <label key={type} className="rounded-2xl border border-slate-200 p-4 text-sm font-bold capitalize"><span className="flex items-center justify-between gap-3">{type.replaceAll("_", " ")}<span className={uploaded.includes(type) ? "text-emerald-700" : "text-slate-400"}>{uploaded.includes(type) ? "Uploaded" : "Required"}</span></span><input disabled={!companyId || busy} type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(event) => upload(type, event.target.files?.[0])} className="mt-3 block w-full text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-950 file:px-3 file:py-2 file:font-bold file:text-white" /></label>)}</div><button type="button" onClick={submit} disabled={uploaded.length !== requiredDocuments.length || busy} className="mt-5 w-full rounded-xl bg-[#0c312d] px-6 py-4 font-black text-white disabled:opacity-40">Submit for review</button></section>
    {message && <p role="status" className="rounded-2xl bg-amber-50 p-4 text-sm text-amber-950 lg:col-span-2">{message}</p>}
  </div></main>;
}

function firstError(body: { message?: string; errors?: Record<string, string[]> }) { return Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? "Request failed."; }
