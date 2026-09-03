"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { platform } from "@/lib/platform";
import { clearAccessToken, clearGuestSession, hasGuestSession, readAccessToken, storeAccessToken } from "@/lib/auth-token";
import { TripSearchPanel } from "@/components/trip-search-panel";

type Terminal = { id: number; name: string; city: string; country: string };
type Seat = { id: number; number: string; position: "window" | "aisle" | "standard"; availability: "available" | "occupied" };
type OptionalService = { code: string; name?: string; price: number };
type PassengerDetails = { fullName: string; phone: string; email: string; type: "adult" | "child" | "infant" | "student" | "senior"; documentNumber: string; passportNumber: string; emergencyName: string; emergencyPhone: string; accessibilityRequirements: string };
type Trip = { id: number; departs_at: string; arrives_at: string; base_fare: string; currency: string; available_seats: number; duration_minutes: number; operator_rating: number; luggage_allowance: string; refund_policy: { label: string }; cancellation_policy: Array<{ minimum_hours: number; refund_percent: number }>; company: { name: string; settings?: { optional_services?: OptionalService[] } }; bus: { model: string; class: string; images: string[] | null; amenities: string[] | null; seats: Seat[]; seat_layout: { columns: number } }; route: { origin: Terminal; destination: Terminal } };
type FareBreakdown = { base_fare: number; subtotal: number; discount: number; taxes: number; terminal_charges: number; fees: number; platform_fee: number; total: number; services: Array<{ code: string; price: number }> };
type BookingPassenger = { id: number; full_name: string; fare: string; seat: { number: string }; ticket?: { id: number; ticket_number: string } | null };
type PaymentHistory = { id: number; provider: string; status: string; amount: string; currency: string; provider_reference: string | null; paid_at: string | null };
type Booking = { id: number; public_id: string; reference: string; total: string; currency: string; status: string; payable_until?: string; fare_breakdown?: FareBreakdown; passengers?: BookingPassenger[] };
type ManagedBooking = Booking & { trip: { id: number; departs_at: string; arrives_at: string; company: { name: string }; route: { origin: Terminal; destination: Terminal } }; passengers: BookingPassenger[]; payments: PaymentHistory[]; review?: { id: number; amount: string } | null };
type Payment = { provider: string; status: string; provider_reference: string; provider_payload?: { instructions?: string; redirect_url?: string } };
type AuthenticatedUser = { id: number; name: string; email: string };
type AuthView = "welcome" | "register" | "login";
export type PortalSection = "book" | "bookings" | "loyalty" | "wallet" | "tracking" | "help";
const portalLinks: Array<{ section: PortalSection; href: string; label: string }> = [
  { section: "bookings", href: "/bookings", label: "Manage booking" },
  { section: "loyalty", href: "/loyalty", label: "Loyalty" },
  { section: "wallet", href: "/wallet", label: "Wallet" },
  { section: "tracking", href: "/tracking", label: "Track a bus" },
  { section: "help", href: "/help", label: "Help" },
];
const portalCopy: Record<PortalSection, { title: string; description: string }> = {
  book: { title: "Your next city is closer than you think.", description: "Compare trusted bus companies, choose your exact seat, and travel with one secure ticket." },
  bookings: { title: "Your journey, under control.", description: "Find a booking, review its passengers, departure, status, and total." },
  loyalty: { title: "Every journey takes you further.", description: "Earn points after completed trips, unlock membership levels, and exchange points for travel discounts." },
  wallet: { title: "Your travel money, in one place.", description: "Deposit funds, receive refund and promotional credits, pay for bookings, and review every transaction." },
  tracking: { title: "Know where your bus is.", description: "Open a secure tracking link and follow your active journey." },
  help: { title: "Support for every journey.", description: "Find quick answers or send a request directly to the Mufambi support team." },
};

export default function Home() {
  return <PassengerPortal section="book" />;
}

export function PassengerPortal({ section }: { section: PortalSection }) {
  const router = useRouter();
  const [terminals, setTerminals] = useState<Terminal[]>([]);
  const [sort, setSort] = useState("departure_asc");
  const [trips, setTrips] = useState<Trip[]>([]);
  const [selectedTrip, setSelectedTrip] = useState<Trip | null>(null);
  const [checkoutTrip, setCheckoutTrip] = useState<Trip | null>(null);
  const [selectedSeats, setSelectedSeats] = useState<number[]>([]);
  const [contact, setContact] = useState({ name: "", phone: "", email: "" });
  const [passengerDetails, setPassengerDetails] = useState<Record<number, PassengerDetails>>({});
  const [couponCode, setCouponCode] = useState("");
  const [selectedServices, setSelectedServices] = useState<string[]>([]);
  const [booking, setBooking] = useState<Booking | null>(null);
  const [payment, setPayment] = useState<Payment | null>(null);
  const [paymentProvider, setPaymentProvider] = useState("demo");
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [authView, setAuthView] = useState<AuthView | null>(null);
  const [authenticatedUser, setAuthenticatedUser] = useState<AuthenticatedUser | null>(null);
  const [isCheckingSession, setIsCheckingSession] = useState(true);
  const [managedBooking, setManagedBooking] = useState<ManagedBooking | null>(null);
  const [bookingLookupMessage, setBookingLookupMessage] = useState("");
  const [trackingToken, setTrackingToken] = useState("");
  const [supportMessage, setSupportMessage] = useState("");
  const [isSubmittingSupport, setIsSubmittingSupport] = useState(false);

  useEffect(() => {
    fetch(`${platform.apiUrl}/terminals`).then((response) => response.json()).then((body) => setTerminals(body.data ?? [])).catch(() => setMessage("The booking service is currently unavailable."));

    const token = readAccessToken();

    if (!token) {
      if (hasGuestSession()) {
        queueMicrotask(() => {
          setAuthenticatedUser({ id: 0, name: "Guest", email: "" });
          setAuthView(null);
          setIsCheckingSession(false);
        });
        return;
      }
      queueMicrotask(() => {
        setAuthView("welcome");
        setIsCheckingSession(false);
      });
      return;
    }

    fetch(`${platform.apiUrl}/auth/me`, {
      headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
    })
      .then(async (response) => {
        if (!response.ok) {
          throw new Error("Session expired");
        }

        setAuthenticatedUser(await response.json());
      })
      .catch(() => {
        clearAccessToken();
        setAuthView("login");
      })
      .finally(() => setIsCheckingSession(false));
  }, []);

  async function logout() {
    const token = readAccessToken();

    try {
      if (token) {
        await fetch(`${platform.apiUrl}/auth/logout`, {
          method: "POST",
          headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
        });
      }
    } finally {
      clearAccessToken();
      clearGuestSession();
      setAuthenticatedUser(null);
      setAuthView("welcome");
    }
  }

  const selectedSeatObjects = useMemo(() => selectedTrip?.bus.seats.filter((seat) => selectedSeats.includes(seat.id)) ?? [], [selectedSeats, selectedTrip]);

  function passengerFor(seatId: number): PassengerDetails {
    return passengerDetails[seatId] ?? { fullName: contact.name, phone: contact.phone, email: contact.email, type: "adult", documentNumber: "", passportNumber: "", emergencyName: "", emergencyPhone: "", accessibilityRequirements: "" };
  }

  function updatePassenger(seatId: number, field: keyof PassengerDetails, value: string) {
    setPassengerDetails((current) => ({ ...current, [seatId]: { ...passengerFor(seatId), [field]: value } as PassengerDetails }));
  }

  async function reserve(event: FormEvent) {
    event.preventDefault(); if (!selectedTrip || !selectedSeats.length) return; setLoading(true); setMessage("");
    try {
      const token = readAccessToken();
      const headers = { "Content-Type": "application/json", Accept: "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}) };
      const lockResponse = await fetch(`${platform.apiUrl}/trips/${selectedTrip.id}/seat-locks`, { method: "POST", headers, body: JSON.stringify({ seat_ids: selectedSeats }) }); const lock = await lockResponse.json();
      if (!lockResponse.ok) throw new Error(firstError(lock));
      const bookingResponse = await fetch(`${platform.apiUrl}/trips/${selectedTrip.id}/bookings`, { method: "POST", headers, body: JSON.stringify({ lock_token: lock.token, contact_name: contact.name, contact_phone: contact.phone, contact_email: contact.email, coupon_code: couponCode || null, optional_services: selectedServices, booking_terms_accepted: true, source: "web", booking_type: selectedSeats.length > 1 ? "group" : "single", passengers: selectedSeatObjects.map((seat) => { const passenger = passengerFor(seat.id); return { seat_id: seat.id, full_name: passenger.fullName, phone: passenger.phone, email: passenger.email, type: passenger.type, document_number: passenger.documentNumber || null, passport_number: passenger.passportNumber || null, emergency_contact: passenger.emergencyName || passenger.emergencyPhone ? { name: passenger.emergencyName, phone: passenger.emergencyPhone } : null, accessibility_requirements: passenger.accessibilityRequirements || null }; }) }) }); const created = await bookingResponse.json();
      if (!bookingResponse.ok) throw new Error(firstError(created));
      setCheckoutTrip(selectedTrip); setBooking(created); setPayment(null); setSelectedTrip(null); setTrips([]); setPassengerDetails({}); setCouponCode(""); setSelectedServices([]);
    } catch (error) { setMessage(error instanceof Error ? error.message : "Booking failed."); } finally { setLoading(false); }
  }

  async function pay() {
    if (!booking) {
      return;
    }

    const token = readAccessToken();
    setLoading(true);
    setMessage("");
    try {
      const paymentPath = token ? `/bookings/${booking.id}/payments` : `/guest/bookings/${booking.public_id}/payments`;
      const response = await fetch(`${platform.apiUrl}${paymentPath}`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}), "Idempotency-Key": crypto.randomUUID() },
        body: JSON.stringify({ provider: paymentProvider, amount: booking.total, context: { channel: "passenger_web" } }),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(firstError(body));
      setPayment(body);
      if (body.provider_payload?.redirect_url) window.location.assign(body.provider_payload.redirect_url);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Payment could not be started.");
    } finally {
      setLoading(false);
    }
  }

  async function findBooking(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const bookingId = form.get("booking_id");
    const token = readAccessToken();
    setBookingLookupMessage("");
    setManagedBooking(null);

    try {
      const response = await fetch(`${platform.apiUrl}/bookings/${bookingId}`, {
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      });
      const body = await response.json();
      if (!response.ok) throw new Error(firstError(body));
      setManagedBooking(body);
    } catch (error) {
      setBookingLookupMessage(error instanceof Error ? error.message : "We could not find that booking.");
    }
  }

  function trackBus(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const token = trackingToken.trim().split("/track/").pop()?.split(/[?#]/)[0];

    if (token) {
      router.push(`/track/${encodeURIComponent(token)}`);
    }
  }

  async function requestHelp(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const supportForm = event.currentTarget;
    setIsSubmittingSupport(true);
    setSupportMessage("");
    const form = new FormData(supportForm);
    const token = readAccessToken();

    try {
      const response = await fetch(`${platform.apiUrl}/support-cases`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}` },
        body: JSON.stringify({ category: form.get("category"), subject: form.get("subject"), description: form.get("description"), priority: "normal" }),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(firstError(body));
      setSupportMessage(`Request ${body.case_number} was sent. Our support team will respond soon.`);
      supportForm.reset();
    } catch (error) {
      setSupportMessage(error instanceof Error ? error.message : "Your request could not be sent.");
    } finally {
      setIsSubmittingSupport(false);
    }
  }

  if (isCheckingSession) {
    return <main className="grid min-h-dvh place-items-center bg-[#0c312d] text-white"><div className="text-center"><span className="mx-auto block size-10 animate-spin rounded-full border-4 border-white/20 border-t-[#ffce54]" /><p className="mt-4 text-sm text-emerald-100/70">Preparing your journey…</p></div></main>;
  }

  if (authView) {
    return <AuthExperience initialView={authView} complete={(user) => { setAuthenticatedUser(user); setAuthView(null); }} />;
  }

  return <main className="min-h-screen bg-[#f5f3ee] text-slate-950">
    <header className="border-b border-white/10 bg-[#0c312d] text-white"><div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-5 px-5 py-5 lg:px-8"><Link href="/home" className="rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-[#ffce54]"><p className="text-2xl font-black">Mufambi</p><p className="text-xs text-emerald-100">Travel across Southern Africa</p></Link><nav className="order-3 flex w-full justify-between gap-3 overflow-x-auto border-t border-white/10 pt-4 text-xs sm:text-sm lg:order-none lg:w-auto lg:justify-start lg:border-0 lg:pt-0">{portalLinks.map((link) => <Link key={link.section} className={`whitespace-nowrap rounded-full px-3 py-2 transition ${section === link.section ? "bg-white text-[#0c312d]" : "hover:text-[#ffce54]"}`} href={link.href}>{link.label}</Link>)}</nav>{authenticatedUser ? <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-full bg-[#ffce54] font-black text-[#0c312d]" aria-hidden="true">{authenticatedUser.name.charAt(0).toUpperCase()}</span><div className="hidden text-right sm:block"><p className="text-xs text-emerald-100/65">Welcome back</p><p className="max-w-40 truncate text-sm font-bold">{authenticatedUser.name}</p></div><button type="button" onClick={logout} className="rounded-full border border-white/25 px-4 py-2 text-sm transition hover:border-white hover:bg-white hover:text-[#0c312d]">Log out</button></div> : <button type="button" onClick={() => setAuthView("welcome")} className="rounded-full border border-white/30 px-4 py-2 text-sm transition hover:border-white hover:bg-white hover:text-[#0c312d]">Sign in</button>}</div></header>
    <section className="bg-[#0c312d] px-5 pb-24 pt-14 text-white lg:px-8"><div className="mx-auto max-w-7xl"><p className="mb-4 text-sm font-bold uppercase tracking-[.24em] text-[#ffce54]">Welcome, {authenticatedUser?.name.split(" ")[0]}</p><h1 className="max-w-3xl text-5xl font-black leading-[1.04] tracking-tight md:text-7xl">{portalCopy[section].title}</h1><p className="mt-6 max-w-xl text-lg leading-8 text-emerald-50/80">{portalCopy[section].description}</p></div></section>
    {section === "tracking" && <TrackingSharePanel />}
    {section === "loyalty" && <LoyaltyPanel />}
    {section === "wallet" && <WalletPanel />}
    {section === "bookings" && <ReviewsPanel />}
    {section === "book" && <>
    <TripSearchPanel terminals={terminals} sort={sort} onSortChange={setSort} onResults={(results) => { setTrips(results as Trip[]); setSelectedTrip(null); setBooking(null); }} onMessage={setMessage} />
    {booking && checkoutTrip && <CheckoutSummary booking={booking} trip={checkoutTrip} />}
    <section className="mx-auto grid max-w-7xl gap-8 px-5 py-12 lg:grid-cols-[1fr_380px] lg:px-8"><div className="flex flex-col gap-5">{message && <div className="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">{message}</div>}{booking && <div className="overflow-hidden rounded-3xl bg-[#0c312d] text-white"><div className="p-8"><p className="text-sm font-bold uppercase tracking-widest text-[#ffce54]">Booking confirmed</p><h2 className="mt-3 text-3xl font-black">You’re going places.</h2><p className="mt-4">Reference <strong>{booking.reference}</strong> · Booking ID <strong>{booking.id}</strong> · Total {booking.currency} {booking.total}</p></div><div className="border-t border-white/10 bg-black/20 p-6 sm:p-8"><p className="text-xs font-bold uppercase tracking-[.2em] text-[#ffce54]">Choose payment method</p><div className="mt-4 grid gap-3 sm:grid-cols-2">{paymentOptions.map((option) => <button type="button" key={option.value} onClick={() => { setPaymentProvider(option.value); setPayment(null); }} className={`flex items-center gap-3 rounded-2xl border p-4 text-left transition ${paymentProvider === option.value ? "border-[#ed1832] bg-[#ed1832]/15" : "border-white/10 bg-white/5 hover:border-white/30"}`}><span className="grid size-10 shrink-0 place-items-center rounded-full bg-white/10 text-lg">{option.icon}</span><span><strong className="block text-sm">{option.label}</strong><span className="text-xs text-white/45">{option.description}</span></span></button>)}</div>{payment ? <div className="mt-5 rounded-2xl border border-emerald-400/30 bg-emerald-400/10 p-4"><p className="font-bold text-emerald-300">Payment {payment.status}</p><p className="mt-1 text-xs text-white/60">Reference: {payment.provider_reference}</p>{payment.provider_payload?.instructions && <p className="mt-2 text-sm">{payment.provider_payload.instructions}</p>}</div> : <button type="button" disabled={loading} onClick={pay} className="mt-5 w-full rounded-full bg-[#ed1832] px-6 py-4 font-black transition hover:bg-[#ff2942] disabled:opacity-50">{loading ? "Starting payment…" : `Pay ${booking.currency} ${booking.total}`}</button>}</div></div>}{trips.map((trip) => <TripCard key={trip.id} trip={trip} choose={() => { setSelectedTrip(trip); setSelectedSeats([]); setBooking(null); setPayment(null); }} />)}{!trips.length && !booking && !message && <div className="rounded-3xl border border-dashed border-black/20 p-12 text-center text-slate-500">Search a route to compare available buses.</div>}</div>
      <aside>{selectedTrip && <form onSubmit={reserve} className="sticky top-6 grid max-h-[calc(100dvh-3rem)] gap-5 overflow-y-auto rounded-3xl bg-white p-6 shadow-xl shadow-slate-900/10">
        <div><p className="text-xs font-bold uppercase tracking-widest text-[#16796f]">Select your seats</p><h2 className="mt-2 text-2xl font-black">{selectedTrip.company.name}</h2><p className="mt-1 text-sm text-slate-500">Seats are held for about 10 minutes while you pay.</p></div>
        <div className="grid grid-cols-4 gap-3 rounded-2xl bg-slate-100 p-4">{selectedTrip.bus.seats.map((seat) => <button type="button" disabled={seat.availability !== "available"} onClick={() => setSelectedSeats((current) => current.includes(seat.id) ? current.filter((id) => id !== seat.id) : current.length < 10 ? [...current, seat.id] : current)} key={seat.id} className={`rounded-lg border p-3 text-sm font-bold ${seat.availability !== "available" ? "cursor-not-allowed bg-slate-200 text-slate-400" : selectedSeats.includes(seat.id) ? "border-[#ef5b35] bg-[#ef5b35] text-white" : "border-emerald-700 bg-white text-emerald-800"}`}>{seat.number}</button>)}</div>
        <fieldset className="grid gap-3"><legend className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">Booking contact</legend><input required placeholder="Contact full name" value={contact.name} onChange={(event) => setContact({ ...contact, name: event.target.value })} className="input" /><input required placeholder="Contact phone" value={contact.phone} onChange={(event) => setContact({ ...contact, phone: event.target.value })} className="input" /><input required type="email" placeholder="Contact email" value={contact.email} onChange={(event) => setContact({ ...contact, email: event.target.value })} className="input" /></fieldset>
        {selectedSeatObjects.map((seat) => { const passenger = passengerFor(seat.id); return <fieldset key={seat.id} className="grid gap-3 rounded-2xl border border-slate-200 p-4"><legend className="px-2 text-xs font-bold uppercase tracking-wider text-[#16796f]">Passenger · Seat {seat.number}</legend><input required placeholder="Full name" value={passenger.fullName} onChange={(event) => updatePassenger(seat.id, "fullName", event.target.value)} className="input" /><div className="grid gap-3 sm:grid-cols-2"><input required placeholder="Phone" value={passenger.phone} onChange={(event) => updatePassenger(seat.id, "phone", event.target.value)} className="input" /><input required type="email" placeholder="Email" value={passenger.email} onChange={(event) => updatePassenger(seat.id, "email", event.target.value)} className="input" /></div><select value={passenger.type} onChange={(event) => updatePassenger(seat.id, "type", event.target.value)} className="input"><option value="adult">Adult</option><option value="child">Child</option><option value="infant">Infant</option><option value="student">Student</option><option value="senior">Senior</option></select><div className="grid gap-3 sm:grid-cols-2"><input placeholder="ID number" value={passenger.documentNumber} onChange={(event) => updatePassenger(seat.id, "documentNumber", event.target.value)} className="input" /><input placeholder="Passport number" value={passenger.passportNumber} onChange={(event) => updatePassenger(seat.id, "passportNumber", event.target.value)} className="input" /></div><div className="grid gap-3 sm:grid-cols-2"><input placeholder="Emergency contact" value={passenger.emergencyName} onChange={(event) => updatePassenger(seat.id, "emergencyName", event.target.value)} className="input" /><input placeholder="Emergency phone" value={passenger.emergencyPhone} onChange={(event) => updatePassenger(seat.id, "emergencyPhone", event.target.value)} className="input" /></div><textarea placeholder="Accessibility requirements" value={passenger.accessibilityRequirements} onChange={(event) => updatePassenger(seat.id, "accessibilityRequirements", event.target.value)} className="input min-h-20 resize-y" /></fieldset>; })}
        {!!selectedTrip.company.settings?.optional_services?.length && <fieldset className="grid gap-2"><legend className="mb-2 text-xs font-bold uppercase tracking-wider text-slate-500">Optional services</legend>{selectedTrip.company.settings.optional_services.map((service) => <label key={service.code} className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3 text-sm"><span><strong>{service.name ?? service.code.replaceAll("_", " ")}</strong><span className="block text-xs text-slate-500">{selectedTrip.currency} {Number(service.price).toFixed(2)}</span></span><input type="checkbox" checked={selectedServices.includes(service.code)} onChange={() => setSelectedServices((current) => current.includes(service.code) ? current.filter((code) => code !== service.code) : [...current, service.code])} className="size-5 accent-[#ef5b35]" /></label>)}</fieldset>}
        <input placeholder="Coupon code" value={couponCode} onChange={(event) => setCouponCode(event.target.value.toUpperCase())} className="input uppercase" />
        <label className="flex items-start gap-3 rounded-2xl bg-amber-50 p-4 text-xs leading-5 text-amber-950"><input required type="checkbox" className="mt-0.5 size-4 shrink-0 accent-[#ef5b35]" /><span>I accept the <Link href="/terms" target="_blank" className="font-bold underline">booking conditions</Link>, operator cancellation policy, fare rules, and luggage conditions.</span></label>
        <div className="flex items-center justify-between border-t border-black/10 pt-5"><div><p className="text-xs text-slate-500">Estimated base fare</p><strong className="text-xl">{selectedTrip.currency} {(Number(selectedTrip.base_fare) * selectedSeats.length).toFixed(2)}</strong><p className="text-[11px] text-slate-400">Final taxes, fees, add-ons and discounts are calculated securely.</p></div><button disabled={!selectedSeats.length || loading} className="rounded-xl bg-[#ef5b35] px-5 py-3 font-bold text-white disabled:opacity-40">{loading ? "Holding…" : "Reserve"}</button></div>
      </form>}</aside></section>
    </>}
    {section === "bookings" && <BookingChangesPanel />}
    {section === "bookings" && <MyBookingsPanel />}
    {section === "bookings" && <NotificationInbox />}
    {section === "bookings" && <section className="bg-white px-5 py-16 lg:px-8"><div className="mx-auto max-w-3xl rounded-3xl border border-slate-200 p-6 sm:p-8"><p className="text-xs font-bold uppercase tracking-[.2em] text-[#16796f]">Manage booking</p><h2 className="mt-2 text-3xl font-black">Find your journey</h2><p className="mt-2 text-sm text-slate-500">Enter the booking ID shown on your confirmation.</p><form onSubmit={findBooking} className="mt-6 flex gap-3"><input required name="booking_id" type="number" min="1" placeholder="Booking ID" className="input" /><button className="shrink-0 rounded-xl bg-[#0c312d] px-5 font-bold text-white">Find</button></form>{bookingLookupMessage && <p role="alert" className="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">{bookingLookupMessage}</p>}{managedBooking && <div className="mt-5 rounded-2xl bg-emerald-50 p-5"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-xs font-bold uppercase text-emerald-700">{managedBooking.status}</p><p className="mt-1 text-xl font-black">{managedBooking.trip.route.origin.city} → {managedBooking.trip.route.destination.city}</p><p className="mt-1 text-sm text-slate-600">{new Date(managedBooking.trip.departs_at).toLocaleString()}</p></div><p className="font-black">{managedBooking.currency} {managedBooking.total}</p></div><p className="mt-4 text-xs text-slate-500">Reference {managedBooking.reference} · {managedBooking.passengers.length} passenger{managedBooking.passengers.length === 1 ? "" : "s"}</p></div>}</div></section>}
    {section === "tracking" && <section className="px-5 py-16 lg:px-8"><div className="mx-auto max-w-3xl rounded-3xl bg-[#0c312d] p-6 text-white sm:p-8"><p className="text-xs font-bold uppercase tracking-[.2em] text-[#ffce54]">Track a bus</p><h2 className="mt-2 text-3xl font-black">Follow your journey live</h2><p className="mt-2 text-sm text-emerald-50/65">Paste the tracking token or full tracking link sent with your trip.</p><form onSubmit={trackBus} className="mt-6 flex flex-col gap-3 sm:flex-row"><input required value={trackingToken} onChange={(event) => setTrackingToken(event.target.value)} placeholder="Tracking token or link" className="min-h-12 min-w-0 flex-1 rounded-xl border border-white/15 bg-white/10 px-4 text-white outline-none placeholder:text-white/35 focus:border-[#ffce54]" /><button className="rounded-xl bg-[#ffce54] px-5 py-3 font-bold text-[#0c312d]">Track now</button></form></div></section>}
    {section === "help" && <section className="bg-[#e8eee9] px-5 py-16 lg:px-8"><div className="mx-auto grid max-w-7xl gap-8 lg:grid-cols-[1fr_1.2fr]"><div><p className="text-xs font-bold uppercase tracking-[.2em] text-[#16796f]">Help centre</p><h2 className="mt-2 text-4xl font-black">How can we help?</h2><div className="mt-6 grid gap-3 text-sm"><details className="rounded-2xl bg-white p-5"><summary className="cursor-pointer font-bold">Where is my booking ID?</summary><p className="mt-3 text-slate-600">It appears on your booking confirmation beside the booking reference.</p></details><details className="rounded-2xl bg-white p-5"><summary className="cursor-pointer font-bold">When does tracking become available?</summary><p className="mt-3 text-slate-600">Tracking is available after the operator activates the trip and shares a tracking link.</p></details><details className="rounded-2xl bg-white p-5"><summary className="cursor-pointer font-bold">How do I change a booking?</summary><p className="mt-3 text-slate-600">Open Manage booking, then send support the booking ID and the change you need.</p></details></div></div><form onSubmit={requestHelp} className="rounded-3xl bg-white p-6 shadow-sm sm:p-8"><h3 className="text-2xl font-black">Contact support</h3><div className="mt-6 grid gap-4"><select required name="category" defaultValue="" className="input"><option value="" disabled>Choose a topic</option><option value="booking">Booking</option><option value="payment">Payment</option><option value="refund">Refund</option><option value="luggage">Luggage</option><option value="lost_item">Lost item</option><option value="other">Other</option></select><input required name="subject" maxLength={200} placeholder="What do you need help with?" className="input" /><textarea required name="description" maxLength={5000} placeholder="Tell us what happened…" className="input min-h-32 resize-y" /><button disabled={isSubmittingSupport} className="rounded-xl bg-[#ef5b35] px-6 py-4 font-bold text-white disabled:opacity-50">{isSubmittingSupport ? "Sending…" : "Send support request"}</button></div>{supportMessage && <p aria-live="polite" className="mt-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{supportMessage}</p>}</form></div></section>}
  </main>;
}

function AuthExperience({ initialView, complete }: { initialView: AuthView; complete: (user: AuthenticatedUser) => void }) {
  const [view, setView] = useState<AuthView>(initialView);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [isAdvancing, setIsAdvancing] = useState(false);

  function advanceToRegistration() {
    if (isAdvancing) {
      return;
    }

    setIsAdvancing(true);
    window.setTimeout(() => {
      setView("register");
      setIsAdvancing(false);
    }, 700);
  }

  async function authenticate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    const form = new FormData(event.currentTarget);
    const isRegistration = view === "register";
    const payload = isRegistration
      ? { name: form.get("name"), email: form.get("email"), phone: form.get("phone"), password: form.get("password"), password_confirmation: form.get("password_confirmation"), terms_accepted: true, device_name: "Mufambi Web" }
      : { email: form.get("email"), password: form.get("password"), device_name: "Mufambi Web" };

    try {
      const response = await fetch(`${platform.apiUrl}/auth/${isRegistration ? "register" : "login"}`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify(payload) });
      const body = await response.json();
      if (!response.ok) throw new Error(firstError(body));
      if (body.two_factor_required) throw new Error("Two-factor verification is required. Continue in the Mufambi app.");
      storeAccessToken(body.token);
      complete(body.user);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "We could not sign you in.");
    } finally {
      setBusy(false);
    }
  }

  return <main aria-label="Mufambi account" className="min-h-dvh bg-[#070709] text-white">
    <div className="relative min-h-dvh w-full overflow-hidden bg-[#070709] text-white">
      {view === "welcome" ? <div className="auth-hero relative flex min-h-dvh flex-col justify-end bg-[url('/images/mufambi-night-coach.png')] bg-cover bg-[center_35%] px-6 pb-8 pt-24 before:absolute before:inset-0 before:bg-linear-to-b before:from-black/5 before:via-black/10 before:to-black sm:px-10 sm:pb-12 lg:bg-[center_42%] lg:px-16 lg:pb-16">
        <div className="relative z-10 w-full max-w-md lg:max-w-lg"><div className="auth-copy"><p className="mb-3 text-xs font-bold uppercase tracking-[.28em] text-[#ff394e]">Mufambi journeys</p><h1 className="text-4xl font-black leading-tight sm:text-5xl lg:text-6xl">Most Affordable<br />Bus Travel Service</h1><p className="mt-4 max-w-md text-sm leading-6 text-white/60 sm:text-base">Convenient and affordable journeys across Southern Africa, all in one place.</p></div><div className="auth-actions"><button type="button" disabled={isAdvancing} onClick={advanceToRegistration} className="relative mt-8 h-[3.75rem] w-full overflow-hidden rounded-full bg-[#ed1832] font-bold transition hover:bg-[#ff2942] disabled:cursor-wait"><span className={`absolute inset-0 grid place-items-center transition-opacity duration-300 ${isAdvancing ? "opacity-35" : "opacity-100"}`}>Get started <span className="ml-2 tracking-[.3em] text-white/45">›››</span></span><span className={`absolute top-2 grid size-11 place-items-center rounded-full bg-white text-xl text-[#ed1832] shadow-lg transition-[left,transform] duration-700 ease-in-out ${isAdvancing ? "left-[calc(100%-3.25rem)] rotate-0" : "left-2 -rotate-12"}`}>→</span></button><button type="button" onClick={() => setView("login")} className="mt-4 w-full text-sm text-white/65 transition hover:text-white">Already have an account? <strong className="text-white underline">Sign in</strong></button></div></div>
      </div> : <div key={view} className="auth-form-enter mx-auto flex min-h-dvh w-full max-w-md flex-col overflow-y-auto px-7 pb-8 pt-20">
        <button type="button" onClick={() => setView("welcome")} className="absolute left-5 top-5 grid size-10 place-items-center rounded-full bg-white/8 text-xl transition hover:bg-white hover:text-black">←</button>
        <p className="text-xs font-bold uppercase tracking-[.28em] text-[#ed1832]">Mufambi account</p><h2 className="mt-3 text-3xl font-black">{view === "register" ? "Create New Account" : "Welcome back"}</h2><p className="mt-2 text-sm text-white/45">{view === "register" ? "Save passengers, tickets, and favourite routes." : "Log in to continue your seamless journey."}</p>
        {view === "login" && <div className="mt-6 rounded-2xl border border-[#ed1832]/25 bg-[#ed1832]/10 p-4 text-sm"><p className="font-bold text-[#ff6677]">Demo login</p><div className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs"><span className="text-white/45">Email</span><code className="text-white">test@example.com</code><span className="text-white/45">Password</span><code className="text-white">password</code></div><p className="mt-2 text-xs text-white/40">The details are already filled in below.</p></div>}
        <form onSubmit={authenticate} className="mt-8 grid gap-5">{view === "register" && <AuthField label="Name" name="name" type="text" placeholder="Your full name" />}<AuthField label="Email" name="email" type="email" placeholder="you@example.com" defaultValue={view === "login" ? "test@example.com" : undefined} /><AuthField label="Password" name="password" type="password" placeholder="At least 10 characters" defaultValue={view === "login" ? "password" : undefined} />{view === "register" && <AuthField label="Confirm password" name="password_confirmation" type="password" placeholder="Repeat your password" />}{view === "register" && <label className="flex items-start gap-3 text-xs text-white/60"><input required type="checkbox" className="mt-0.5 size-4 accent-[#ed1832]" /><span>I agree to the <strong className="text-white">Terms &amp; Conditions</strong></span></label>}{error && <p role="alert" className="rounded-2xl border border-red-500/20 bg-red-500/10 p-4 text-sm text-red-200">{error}</p>}<button disabled={busy} className="mt-2 rounded-full bg-[#ed1832] px-6 py-4 font-bold transition hover:bg-[#ff2942] disabled:opacity-50">{busy ? "Please wait…" : view === "register" ? "Sign Up" : "Log in"}</button></form>
        <div className="mt-auto pt-8 text-center text-sm text-white/45">{view === "register" ? "Already have an account?" : "New to Mufambi?"} <button type="button" onClick={() => { setView(view === "register" ? "login" : "register"); setError(""); }} className="font-bold text-white underline">{view === "register" ? "Sign in" : "Create account"}</button></div>
      </div>}
    </div>
  </main>;
}

function AuthField({ label, name, type, placeholder, defaultValue }: { label: string; name: string; type: string; placeholder: string; defaultValue?: string }) { return <><label className="grid gap-2 text-xs font-semibold text-white/60">{label}<input required name={name} type={type} placeholder={placeholder} defaultValue={defaultValue} autoComplete={type === "password" ? "current-password" : name} className="min-h-14 rounded-2xl border border-white/5 bg-white/5 px-5 text-sm text-white outline-none placeholder:text-white/20 focus:border-[#ed1832] focus:ring-2 focus:ring-[#ed1832]/20" /></label>{name === "name" && <label className="grid gap-2 text-xs font-semibold text-white/60">Phone number<input required name="phone" type="tel" placeholder="+263 77 123 4567" autoComplete="tel" className="min-h-14 rounded-2xl border border-white/5 bg-white/5 px-5 text-sm text-white outline-none placeholder:text-white/20 focus:border-[#ed1832] focus:ring-2 focus:ring-[#ed1832]/20"/></label>}</>; }

const paymentOptions = [
  { value: "demo", label: "Demo payment", description: "Instant test payment", icon: "D" },
  { value: "ecocash", label: "EcoCash", description: "Pay from your mobile wallet", icon: "E" },
  { value: "onemoney", label: "OneMoney", description: "Mobile money payment", icon: "1" },
  { value: "innbucks", label: "InnBucks", description: "Pay with your InnBucks wallet", icon: "I" },
  { value: "paynow", label: "Paynow", description: "Secure online checkout", icon: "P" },
  { value: "visa", label: "Visa", description: "Visa debit or credit card", icon: "V" },
  { value: "mastercard", label: "Mastercard", description: "Mastercard debit or credit card", icon: "M" },
  { value: "bank_transfer", label: "Bank transfer", description: "Transfer using the supplied reference", icon: "B" },
  { value: "cash_agent", label: "Agent payment", description: "Pay an authorised Mufambi agent", icon: "A" },
  { value: "cash_branch", label: "Approved office", description: "Pay cash at an approved office", icon: "$" },
  { value: "passenger_wallet", label: "Passenger wallet", description: "Use your available wallet balance", icon: "W" },
];

function CheckoutSummary({ booking, trip }: { booking: Booking; trip: Trip }) {
  const fare = booking.fare_breakdown;
  return <section className="mx-auto -mb-6 mt-10 w-full max-w-7xl px-5 lg:px-8"><div className="grid gap-6 rounded-3xl border border-emerald-900/10 bg-white p-6 shadow-lg shadow-slate-900/5 lg:grid-cols-[1fr_1fr]"><div><p className="text-xs font-black uppercase tracking-[.2em] text-[#16796f]">Checkout · seats held</p><h2 className="mt-2 text-2xl font-black">{trip.route.origin.city} → {trip.route.destination.city}</h2><p className="mt-2 text-sm text-slate-500">{trip.company.name} · {new Date(trip.departs_at).toLocaleString()}</p><div className="mt-5 grid gap-2">{booking.passengers?.map((passenger) => <div key={passenger.id} className="flex justify-between rounded-xl bg-slate-50 px-4 py-3 text-sm"><span><strong>{passenger.full_name}</strong><span className="ml-2 text-slate-500">Seat {passenger.seat.number}</span></span><span>{booking.currency} {passenger.fare}</span></div>)}</div><p className="mt-4 text-xs text-amber-700">Complete payment before {booking.payable_until ? new Date(booking.payable_until).toLocaleTimeString() : "the hold expires"}.</p></div>{fare && <div><p className="text-xs font-black uppercase tracking-[.2em] text-[#16796f]">Complete fare breakdown</p><dl className="mt-4 grid gap-2 text-sm"><FareLine label="Passenger and seat fares" value={fare.subtotal - fare.services.reduce((sum, service) => sum + Number(service.price), 0)} currency={booking.currency} />{fare.services.map((service) => <FareLine key={service.code} label={service.code.replaceAll("_", " ")} value={service.price} currency={booking.currency} />)}<FareLine label="Discount" value={-fare.discount} currency={booking.currency} /><FareLine label="Taxes" value={fare.taxes} currency={booking.currency} /><FareLine label="Terminal and service charges" value={fare.fees} currency={booking.currency} /><FareLine label="Platform fee" value={fare.platform_fee} currency={booking.currency} /><div className="mt-2 flex justify-between border-t border-slate-200 pt-3 text-lg font-black"><dt>Total</dt><dd>{booking.currency} {Number(fare.total).toFixed(2)}</dd></div></dl></div>}</div></section>;
}

function FareLine({ label, value, currency }: { label: string; value: number; currency: string }) { return <div className="flex justify-between gap-4 capitalize"><dt className="text-slate-500">{label}</dt><dd className={value < 0 ? "font-bold text-emerald-700" : "font-semibold"}>{value < 0 ? "−" : ""}{currency} {Math.abs(Number(value)).toFixed(2)}</dd></div>; }

type CancellationQuote = { refund_percent: number; eligible_amount: number; cancellation_charge: number; refund_amount: number; currency: string; rules: Array<{ minimum_hours: number; refund_percent: number }> };

function BookingChangesPanel() {
  const [bookings, setBookings] = useState<ManagedBooking[]>([]);
  const [selectedBookingId, setSelectedBookingId] = useState(0);
  const [quote, setQuote] = useState<CancellationQuote | null>(null);
  const [options, setOptions] = useState<Trip[]>([]);
  const [targetTrip, setTargetTrip] = useState<Trip | null>(null);
  const [targetSeats, setTargetSeats] = useState<number[]>([]);
  const [date, setDate] = useState("");
  const [message, setMessage] = useState("");
  const token = readAccessToken();
  const selectedBooking = bookings.find((item) => item.id === selectedBookingId);

  useEffect(() => {
    if (!token) return;
    fetch(`${platform.apiUrl}/bookings?category=upcoming`, { headers: { Accept: "application/json", Authorization: `Bearer ${token}` } })
      .then((response) => response.json()).then((body) => { setBookings(body.data ?? []); setSelectedBookingId(body.data?.[0]?.id ?? 0); });
  }, [token]);

  async function cancellationQuote() {
    if (!selectedBooking) return;
    setMessage("");
    const response = await fetch(`${platform.apiUrl}/bookings/${selectedBooking.id}/cancellation-quote`, { headers: { Accept: "application/json", Authorization: `Bearer ${token}` } });
    const body = await response.json();
    if (!response.ok) { setMessage(firstError(body)); return; }
    setQuote(body);
  }

  async function cancelBooking(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selectedBooking || !quote) return;
    const form = new FormData(event.currentTarget);
    const response = await fetch(`${platform.apiUrl}/bookings/${selectedBooking.id}/cancellations`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}` }, body: JSON.stringify({ reason: form.get("reason"), refund_method: form.get("refund_method") }) });
    const body = await response.json();
    if (!response.ok) { setMessage(firstError(body)); return; }
    setMessage(`Booking cancelled. Refund: ${body.currency} ${Number(body.refund_amount).toFixed(2)}.`); setQuote(null); setBookings((items) => items.filter((item) => item.id !== selectedBooking.id));
  }

  async function loadRescheduleOptions() {
    if (!selectedBooking) return;
    const query = date ? `?date=${date}` : "";
    const response = await fetch(`${platform.apiUrl}/bookings/${selectedBooking.id}/reschedule-options${query}`, { headers: { Accept: "application/json", Authorization: `Bearer ${token}` } });
    const body = await response.json();
    if (!response.ok) { setMessage(firstError(body)); return; }
    setOptions(body.data ?? []); setTargetTrip(null); setTargetSeats([]); setMessage(body.data?.length ? "Choose a replacement trip and seats." : "No eligible replacement trips were found.");
  }

  async function reschedule() {
    if (!selectedBooking || !targetTrip || targetSeats.length !== selectedBooking.passengers.length) return;
    setMessage("Holding replacement seats…");
    const headers = { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}` };
    const lockResponse = await fetch(`${platform.apiUrl}/trips/${targetTrip.id}/seat-locks`, { method: "POST", headers, body: JSON.stringify({ seat_ids: targetSeats }) });
    const lock = await lockResponse.json();
    if (!lockResponse.ok) { setMessage(firstError(lock)); return; }
    const response = await fetch(`${platform.apiUrl}/bookings/${selectedBooking.id}/reschedules`, { method: "POST", headers, body: JSON.stringify({ trip_id: targetTrip.id, lock_token: lock.token, seats: selectedBooking.passengers.map((passenger, index) => ({ passenger_id: passenger.id, seat_id: targetSeats[index] })) }) });
    const body = await response.json();
    if (!response.ok) { setMessage(firstError(body)); return; }
    setMessage(`Trip updated. Fare difference: ${selectedBooking.currency} ${Number(body.fare_difference).toFixed(2)}${body.fare_difference > 0 ? ". Payment is required before the hold expires." : ". Updated tickets are ready."}`); setOptions([]); setTargetTrip(null); setTargetSeats([]);
  }

  if (!token) return null;
  return <section className="bg-white px-5 py-12 lg:px-8"><div className="mx-auto max-w-7xl rounded-3xl border border-slate-200 p-6"><p className="text-xs font-black uppercase tracking-[.2em] text-[#16796f]">Change a journey</p><div className="mt-5 grid gap-5 lg:grid-cols-2"><div className="grid content-start gap-4"><select value={selectedBookingId} onChange={(event) => { setSelectedBookingId(Number(event.target.value)); setQuote(null); setOptions([]); }} className="input"><option value="0">Choose an upcoming booking</option>{bookings.map((item) => <option key={item.id} value={item.id}>{item.reference} · {item.trip.route.origin.city} to {item.trip.route.destination.city}</option>)}</select><button type="button" disabled={!selectedBooking} onClick={cancellationQuote} className="rounded-xl border border-red-700 px-5 py-3 font-bold text-red-700 disabled:opacity-40">Review cancellation rules and charges</button>{quote && <form onSubmit={cancelBooking} className="grid gap-3 rounded-2xl bg-red-50 p-4 text-sm"><p><strong>{quote.refund_percent}% refund</strong> · Charge {quote.currency} {quote.cancellation_charge.toFixed(2)} · Refund {quote.currency} {quote.refund_amount.toFixed(2)}</p><div className="grid gap-1 text-xs text-slate-600">{quote.rules.map((rule) => <p key={rule.minimum_hours}>Cancel at least {rule.minimum_hours} hours before departure: {rule.refund_percent}% refund</p>)}</div><textarea required name="reason" placeholder="Reason for cancellation" className="input min-h-20" /><select name="refund_method" className="input"><option value="original">Original payment method</option><option value="wallet">Passenger wallet</option><option value="voucher">Travel voucher</option></select><button className="rounded-xl bg-red-700 px-5 py-3 font-bold text-white">Confirm cancellation</button></form>}</div><div className="grid content-start gap-4"><div className="flex gap-3"><input type="date" value={date} onChange={(event) => setDate(event.target.value)} className="input" /><button type="button" disabled={!selectedBooking} onClick={loadRescheduleOptions} className="shrink-0 rounded-xl bg-[#0c312d] px-4 font-bold text-white disabled:opacity-40">Find trips</button></div>{options.map((trip) => <button type="button" key={trip.id} onClick={() => { setTargetTrip(trip); setTargetSeats([]); }} className={`rounded-2xl border p-4 text-left ${targetTrip?.id === trip.id ? "border-[#ef5b35] bg-orange-50" : "border-slate-200"}`}><strong>{new Date(trip.departs_at).toLocaleString()}</strong><span className="mt-1 block text-sm text-slate-500">{trip.currency} {trip.base_fare} · {trip.available_seats} seats</span></button>)}{targetTrip && <div className="grid grid-cols-4 gap-2 rounded-2xl bg-slate-100 p-4">{targetTrip.bus.seats.map((seat) => <button type="button" key={seat.id} disabled={seat.availability !== "available"} onClick={() => setTargetSeats((current) => current.includes(seat.id) ? current.filter((id) => id !== seat.id) : current.length < (selectedBooking?.passengers.length ?? 0) ? [...current, seat.id] : current)} className={`rounded-lg p-2 text-sm font-bold disabled:opacity-30 ${targetSeats.includes(seat.id) ? "bg-[#ef5b35] text-white" : "bg-white"}`}>{seat.number}</button>)}</div>}<button type="button" onClick={reschedule} disabled={!targetTrip || targetSeats.length !== selectedBooking?.passengers.length} className="rounded-xl bg-[#ef5b35] px-5 py-3 font-bold text-white disabled:opacity-40">Confirm new trip and seats</button></div></div>{message && <p role="status" className="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">{message}</p>}</div></section>;
}

function TrackingSharePanel() {
  const [bookings, setBookings] = useState<ManagedBooking[]>([]);
  const [message, setMessage] = useState("");
  const token = readAccessToken();
  useEffect(() => { if (!token) return; fetch(`${platform.apiUrl}/bookings?category=upcoming`, { headers: { Accept: "application/json", Authorization: `Bearer ${token}` } }).then((response) => response.json()).then((body) => setBookings(body.data ?? [])); }, [token]);
  async function share(item: ManagedBooking) {
    const response = await fetch(`${platform.apiUrl}/trips/${item.trip.id}/tracking-links`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}` }, body: JSON.stringify({ booking_id: item.id, privacy_precision: "approximate", expires_in_hours: 24 }) });
    const body = await response.json(); if (!response.ok) { setMessage(firstError(body)); return; }
    const url = `${window.location.origin}/track/${body.token}`;
    const canShare = typeof navigator.share === "function";
    if (canShare) await navigator.share({ title: `Track booking ${item.reference}`, url }); else await navigator.clipboard.writeText(url);
    setMessage(canShare ? "Tracking link shared." : "Tracking link copied to clipboard.");
  }
  if (!token || !bookings.length) return null;
  return <section className="bg-[#e8eee9] px-5 py-10 lg:px-8"><div className="mx-auto max-w-7xl"><p className="text-xs font-black uppercase tracking-[.2em] text-[#16796f]">Share live tracking</p><div className="mt-4 flex flex-wrap gap-3">{bookings.map((item) => <button key={item.id} type="button" onClick={() => share(item)} className="rounded-xl bg-white px-5 py-3 text-sm font-bold shadow-sm">Share {item.reference}</button>)}</div>{message && <p className="mt-3 text-sm text-emerald-900">{message}</p>}</div></section>;
}

function NotificationInbox() {
  const [notifications, setNotifications] = useState<Array<{ id: number; subject: string; body: string; event_type: string; read_at: string | null; created_at: string }>>([]);
  const token = readAccessToken();
  useEffect(() => { if (!token) return; fetch(`${platform.apiUrl}/notifications`, { headers: { Accept: "application/json", Authorization: `Bearer ${token}` } }).then((response) => response.json()).then((body) => setNotifications(body.data ?? [])); }, [token]);
  if (!token) return null;
  return <section className="bg-[#0c312d] px-5 py-12 text-white lg:px-8"><div className="mx-auto max-w-7xl"><p className="text-xs font-black uppercase tracking-[.2em] text-[#ffce54]">Passenger notifications</p><h2 className="mt-2 text-3xl font-black">Journey updates</h2><div className="mt-6 grid gap-3 md:grid-cols-2">{notifications.map((item) => <article key={item.id} className={`rounded-2xl p-5 ${item.read_at ? "bg-white/5" : "bg-white/10 ring-1 ring-[#ffce54]/40"}`}><p className="text-xs font-bold uppercase text-[#ffce54]">{item.event_type.replaceAll("_", " ")}</p><h3 className="mt-2 font-black">{item.subject}</h3><p className="mt-2 text-sm text-emerald-50/70">{item.body}</p></article>)}{!notifications.length && <p className="text-sm text-emerald-50/60">No journey updates yet.</p>}</div></div></section>;
}

function LoyaltyPanel() {
  const [summary, setSummary] = useState<{ account: { points_balance: number; lifetime_points: number; membership_level: string; referral_code: string }; transactions: Array<{ id: number; name: string; points: number; created_at: string }>; levels: Record<string, number> } | null>(null);
  const [message, setMessage] = useState("Loading your rewards…");
  const token = readAccessToken();
  const load = async () => {
    if (!token) return;
    const response = await fetch(`${platform.apiUrl}/loyalty`, { headers: { Accept: "application/json", Authorization: `Bearer ${token}` } });
    const body = await response.json();
    if (!response.ok) throw new Error(firstError(body));
    setSummary(body); setMessage("");
  };
  useEffect(() => { load().catch((error) => setMessage(error instanceof Error ? error.message : "Rewards are unavailable.")); }, [token]);
  async function redeem(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const form = new FormData(event.currentTarget); const response = await fetch(`${platform.apiUrl}/loyalty/redemptions`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}` }, body: JSON.stringify({ points: Number(form.get("points")) }) }); const body = await response.json(); setMessage(response.ok ? `Discount code ${body.coupon_code} is ready to use.` : firstError(body)); if (response.ok) await load(); }
  async function claim(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const form = new FormData(event.currentTarget); const response = await fetch(`${platform.apiUrl}/loyalty/rewards`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}` }, body: JSON.stringify({ type: form.get("type"), code: form.get("code") }) }); const body = await response.json(); setMessage(response.ok ? "Reward added to your balance." : firstError(body)); if (response.ok) { event.currentTarget.reset(); await load(); } }
  if (!token) return <section className="mx-auto max-w-7xl px-5 py-12"><p className="rounded-2xl bg-white p-6">Sign in to join the loyalty programme.</p></section>;
  return <section className="mx-auto max-w-7xl px-5 py-12 lg:px-8"><div className="grid gap-6 lg:grid-cols-[1.2fr_.8fr]"><div className="rounded-3xl bg-[#0c312d] p-8 text-white"><p className="text-xs font-black uppercase tracking-[.2em] text-[#ffce54]">{summary?.account.membership_level ?? "Member"}</p><p className="mt-4 text-5xl font-black">{summary?.account.points_balance ?? 0}</p><p className="text-emerald-50/70">available points · {summary?.account.lifetime_points ?? 0} earned all time</p><div className="mt-7 grid grid-cols-2 gap-2 sm:grid-cols-4">{Object.entries(summary?.levels ?? { Bronze: 0, Silver: 500, Gold: 1500, Platinum: 5000 }).map(([level, threshold]) => <div key={level} className="rounded-xl bg-white/10 p-3"><strong className="block text-sm">{level}</strong><span className="text-xs text-white/55">{threshold}+ pts</span></div>)}</div><div className="mt-5 rounded-2xl bg-white/10 p-4"><span className="text-xs text-white/55">Your referral code</span><strong className="block tracking-widest">{summary?.account.referral_code ?? "—"}</strong></div></div><div className="grid gap-4"><form onSubmit={redeem} className="rounded-3xl bg-white p-6 shadow-sm"><h2 className="text-xl font-black">Exchange points</h2><p className="mt-1 text-sm text-slate-500">100 points = 1 unit off your next booking.</p><div className="mt-4 flex gap-2"><input className="input min-w-0" type="number" name="points" min="100" step="100" required placeholder="500"/><button className="rounded-xl bg-[#ed1832] px-5 font-bold text-white">Redeem</button></div></form><form onSubmit={claim} className="rounded-3xl bg-white p-6 shadow-sm"><h2 className="text-xl font-black">Claim a reward</h2><div className="mt-4 flex flex-col gap-2 sm:flex-row"><select name="type" className="input"><option value="referral">Referral</option><option value="promotion">Promotion</option><option value="route">Route reward</option><option value="operator">Operator reward</option><option value="voucher">Voucher</option></select><input className="input min-w-0" name="code" required placeholder="Reward code"/><button className="rounded-xl bg-[#0c312d] px-5 py-3 font-bold text-white">Claim</button></div></form></div></div>{message && <p role="status" className="mt-5 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">{message}</p>}<div className="mt-8"><h2 className="text-2xl font-black">Points history</h2><div className="mt-4 divide-y divide-slate-100 rounded-3xl bg-white px-6">{summary?.transactions.map((entry) => <div key={entry.id} className="flex justify-between gap-4 py-4"><span>{entry.name}<small className="block text-slate-400">{new Date(entry.created_at).toLocaleDateString()}</small></span><strong className={entry.points >= 0 ? "text-emerald-700" : "text-[#ed1832]"}>{entry.points >= 0 ? "+" : ""}{entry.points}</strong></div>)}</div></div></section>;
}

function WalletPanel() {
  type WalletSummary = { wallet: { available_balance: string; held_balance: string; currency: string; is_frozen: boolean }; transactions: { data: Array<{ id: number; name: string; transaction_type: string; direction: string; amount: string; balance_after: string; occurred_at: string }> } };
  const [summary, setSummary] = useState<WalletSummary | null>(null); const [message, setMessage] = useState("Loading your wallet…"); const token = readAccessToken();
  const load = async () => { if (!token) return; const response = await fetch(`${platform.apiUrl}/wallet`, { headers: { Accept: "application/json", Authorization: `Bearer ${token}` } }); const body = await response.json(); if (!response.ok) throw new Error(firstError(body)); setSummary(body); setMessage(""); };
  useEffect(() => { load().catch((error) => setMessage(error instanceof Error ? error.message : "Wallet unavailable.")); }, [token]);
  async function deposit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const form = new FormData(event.currentTarget); const response = await fetch(`${platform.apiUrl}/wallet/deposits`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}` }, body: JSON.stringify({ amount: Number(form.get("amount")), reference: crypto.randomUUID() }) }); const body = await response.json(); setMessage(response.ok ? "Deposit credited to your wallet." : firstError(body)); if (response.ok) { event.currentTarget.reset(); await load(); } }
  async function toggleFreeze() { const response = await fetch(`${platform.apiUrl}/wallet/security`, { method: "PUT", headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}` }, body: JSON.stringify({ is_frozen: !summary?.wallet.is_frozen }) }); const body = await response.json(); setMessage(response.ok ? (body.wallet.is_frozen ? "Wallet frozen." : "Wallet unfrozen.") : firstError(body)); if (response.ok) await load(); }
  if (!token) return <section className="mx-auto max-w-7xl px-5 py-12"><p className="rounded-2xl bg-white p-6">Sign in to access your passenger wallet.</p></section>;
  return <section className="mx-auto max-w-7xl px-5 py-12 lg:px-8"><div className="grid gap-6 lg:grid-cols-[1fr_.8fr]"><article className="rounded-3xl bg-[#0c312d] p-8 text-white"><p className="text-xs font-black uppercase tracking-[.2em] text-[#ffce54]">Available balance</p><p className="mt-4 text-5xl font-black">{summary?.wallet.currency ?? "USD"} {summary?.wallet.available_balance ?? "0.00"}</p><div className="mt-6 flex flex-wrap gap-3 text-sm"><span className="rounded-full bg-white/10 px-4 py-2">Held {summary?.wallet.held_balance ?? "0.00"}</span><button type="button" onClick={toggleFreeze} className="rounded-full border border-white/30 px-4 py-2 font-bold">{summary?.wallet.is_frozen ? "Unfreeze wallet" : "Freeze wallet"}</button></div></article><form onSubmit={deposit} className="rounded-3xl bg-white p-6 shadow-sm"><h2 className="text-2xl font-black">Deposit funds</h2><p className="mt-2 text-sm text-slate-500">Add funds for bookings and receive refunds or promotional credits here.</p><div className="mt-5 flex gap-2"><input name="amount" type="number" min="1" max="10000" step="0.01" required className="input min-w-0" placeholder="Amount"/><button className="rounded-xl bg-[#ef5b35] px-5 font-black text-white">Deposit</button></div></form></div>{message && <p role="status" className="mt-5 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">{message}</p>}<div className="mt-8"><h2 className="text-2xl font-black">Transaction history</h2><div className="mt-4 divide-y divide-slate-100 rounded-3xl bg-white px-6">{summary?.transactions.data.map((entry) => <div key={entry.id} className="flex items-center justify-between gap-4 py-4"><div><strong>{entry.name}</strong><p className="text-xs capitalize text-slate-400">{entry.transaction_type.replaceAll("_", " ")} · {new Date(entry.occurred_at).toLocaleString()}</p></div><div className="text-right"><strong className={entry.direction === "credit" ? "text-emerald-700" : "text-[#ed1832]"}>{entry.direction === "credit" ? "+" : "−"}{entry.amount}</strong><p className="text-xs text-slate-400">Balance {entry.balance_after}</p></div></div>)}{!summary?.transactions.data.length && <p className="py-6 text-sm text-slate-500">No wallet transactions yet.</p>}</div></div></section>;
}

function ReviewForm({ booking, submitted }: { booking: ManagedBooking; submitted: () => void }) {
  const [message, setMessage] = useState("");
  if (booking.review || new Date(booking.trip.arrives_at) > new Date() || !["confirmed", "completed"].includes(booking.status)) return booking.review ? <p className="mt-4 text-sm font-bold text-emerald-700">Rated {booking.review.amount}/5 · Thank you for your feedback.</p> : null;
  async function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const form = new FormData(event.currentTarget); const ratings = ["cleanliness", "comfort", "punctuality", "driver_professionalism", "customer_service", "overall_experience"]; const payload = Object.fromEntries(ratings.map((key) => [key, Number(form.get(key))])); Object.assign(payload, { comment: form.get("comment") || null }); const response = await fetch(`${platform.apiUrl}/bookings/${booking.id}/review`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${readAccessToken()}` }, body: JSON.stringify(payload) }); const body = await response.json(); if (!response.ok) { setMessage(firstError(body)); return; } setMessage("Thanks—your ratings help passengers choose with confidence."); submitted(); }
  return <form onSubmit={submit} className="mt-5 rounded-2xl border border-emerald-100 bg-emerald-50/50 p-5"><h4 className="font-black">Rate this journey</h4><div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{[["cleanliness", "Bus cleanliness"], ["comfort", "Comfort"], ["punctuality", "Punctuality"], ["driver_professionalism", "Driver professionalism"], ["customer_service", "Customer service"], ["overall_experience", "Overall experience"]].map(([name, label]) => <label key={name} className="text-sm font-bold">{label}<select name={name} required defaultValue="" className="input mt-1"><option value="" disabled>Select</option>{[5,4,3,2,1].map((score) => <option key={score} value={score}>{score} / 5</option>)}</select></label>)}</div><textarea name="comment" maxLength={2000} className="input mt-4 min-h-24" placeholder="Share any additional comments (optional)"/><button className="mt-3 rounded-xl bg-[#0c312d] px-5 py-3 font-bold text-white">Submit review</button>{message && <p role="status" className="mt-3 text-sm">{message}</p>}</form>;
}

function ReviewsPanel() {
  const [bookings, setBookings] = useState<ManagedBooking[]>([]);
  const token = readAccessToken();
  useEffect(() => { if (!token) return; fetch(`${platform.apiUrl}/bookings?category=completed`, { headers: { Accept: "application/json", Authorization: `Bearer ${token}` } }).then((response) => response.json()).then((body) => setBookings(body.data ?? [])); }, [token]);
  const reviewable = bookings.filter((booking) => !booking.review);
  if (!reviewable.length) return null;
  return <section className="mx-auto max-w-7xl px-5 pt-12 lg:px-8"><div className="rounded-3xl bg-white p-6 shadow-sm"><p className="text-xs font-black uppercase tracking-[.2em] text-[#16796f]">Ratings and reviews</p><h2 className="mt-2 text-2xl font-black">How was your trip?</h2>{reviewable.map((booking) => <ReviewForm key={booking.id} booking={booking} submitted={() => setBookings((current) => current.filter((item) => item.id !== booking.id))}/>)}</div></section>;
}

function MyBookingsPanel() {
  const [category, setCategory] = useState("");
  const [bookings, setBookings] = useState<ManagedBooking[]>([]);
  const [status, setStatus] = useState("Loading your bookings…");

  useEffect(() => {
    const token = readAccessToken();
    if (!token) {
      queueMicrotask(() => setStatus("Sign in to view all bookings, tickets, payments, and receipts."));
      return;
    }
    const controller = new AbortController();
    fetch(`${platform.apiUrl}/bookings${category ? `?category=${category}` : ""}`, { headers: { Accept: "application/json", Authorization: `Bearer ${token}` }, signal: controller.signal })
      .then(async (response) => { const body = await response.json(); if (!response.ok) throw new Error(firstError(body)); setBookings(body.data ?? []); setStatus(body.data?.length ? "" : "No bookings in this category."); })
      .catch((error) => { if (error instanceof Error && error.name !== "AbortError") setStatus(error.message); });
    return () => controller.abort();
  }, [category]);

  useEffect(() => {
    const token = readAccessToken();
    if (!token || !bookings.length) return;
    const links = Array.from(document.querySelectorAll<HTMLAnchorElement>(`a[href^="${platform.apiUrl}/tickets/"], a[href^="${platform.apiUrl}/bookings/"]`));
    const download = async (event: Event) => {
      event.preventDefault();
      const link = event.currentTarget as HTMLAnchorElement;
      try {
        const response = await fetch(link.href, { headers: { Accept: "application/pdf", Authorization: `Bearer ${token}` } });
        if (!response.ok) throw new Error("The document could not be downloaded.");
        const url = URL.createObjectURL(await response.blob());
        const temporaryLink = document.createElement("a");
        temporaryLink.href = url;
        temporaryLink.download = link.href.includes("/receipt") ? "booking-receipt.pdf" : "bus-ticket.pdf";
        temporaryLink.click();
        URL.revokeObjectURL(url);
      } catch (error) {
        setStatus(error instanceof Error ? error.message : "The document could not be downloaded.");
      }
    };
    links.forEach((link) => link.addEventListener("click", download));
    return () => links.forEach((link) => link.removeEventListener("click", download));
  }, [bookings]);

  const token = readAccessToken();
  return <section className="bg-[#edf1ef] px-5 py-12 lg:px-8"><div className="mx-auto max-w-7xl"><div className="flex flex-wrap items-end justify-between gap-4"><div><p className="text-xs font-black uppercase tracking-[.2em] text-[#16796f]">My bookings</p><h2 className="mt-2 text-3xl font-black">Trips, payments and tickets</h2></div><select value={category} onChange={(event) => setCategory(event.target.value)} className="input max-w-56"><option value="">All bookings</option><option value="upcoming">Upcoming trips</option><option value="completed">Completed trips</option><option value="cancelled">Cancelled trips</option><option value="pending">Pending bookings</option></select></div>{status && <p className="mt-6 rounded-2xl bg-white p-5 text-sm text-slate-600">{status}</p>}<div className="mt-6 grid gap-5">{bookings.map((item) => <article key={item.id} className="rounded-3xl bg-white p-6 shadow-sm"><div className="flex flex-wrap justify-between gap-4"><div><span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-black uppercase text-emerald-800">{item.status.replaceAll("_", " ")}</span><h3 className="mt-3 text-xl font-black">{item.trip.route.origin.city} → {item.trip.route.destination.city}</h3><p className="mt-1 text-sm text-slate-500">{item.trip.company.name} · {new Date(item.trip.departs_at).toLocaleString()}</p></div><div className="text-right"><p className="font-black">{item.currency} {item.total}</p><p className="text-xs text-slate-500">{item.reference}</p></div></div><div className="mt-5 grid gap-3 md:grid-cols-2"><div className="rounded-2xl bg-slate-50 p-4"><p className="text-xs font-black uppercase text-slate-500">Passengers and tickets</p>{item.passengers.map((passenger) => <div key={passenger.id} className="mt-3 flex items-center justify-between gap-3 text-sm"><span>{passenger.full_name} · Seat {passenger.seat.number}</span>{passenger.ticket && <a href={`${platform.apiUrl}/tickets/${passenger.ticket.id}/pdf`} className="font-bold text-emerald-800 underline">Ticket {passenger.ticket.ticket_number}</a>}</div>)}</div><div className="rounded-2xl bg-slate-50 p-4"><p className="text-xs font-black uppercase text-slate-500">Payment history</p>{item.payments.length ? item.payments.map((payment) => <div key={payment.id} className="mt-3 flex justify-between gap-3 text-sm"><span className="capitalize">{payment.provider.replaceAll("_", " ")} · {payment.status}</span><strong>{payment.currency} {payment.amount}</strong></div>) : <p className="mt-3 text-sm text-slate-500">No payment attempts yet.</p>}</div></div>{token && item.payments.some((entry) => entry.status === "paid") && <a href={`${platform.apiUrl}/bookings/${item.id}/receipt`} className="mt-4 inline-flex rounded-xl border border-[#0c312d] px-4 py-2 text-sm font-black text-[#0c312d]">Download receipt</a>}</article>)}</div></div></section>;
}

// Review controls are rendered beside each completed booking without changing the booking-card layout.
function TripCard({ trip, choose }: { trip: Trip; choose: () => void }) { return <article className="rounded-3xl border border-black/10 bg-white p-6 transition hover:-translate-y-0.5 hover:shadow-lg"><div className="flex flex-col justify-between gap-5 sm:flex-row"><div><p className="text-sm font-bold text-[#16796f]">{trip.company.name} · {trip.bus.class}</p><div className="mt-3 flex items-center gap-4"><strong className="text-3xl">{time(trip.departs_at)}</strong><span className="text-sm text-slate-500">{duration(trip.duration_minutes)} →</span><strong className="text-3xl">{time(trip.arrives_at)}</strong></div><p className="mt-2 text-sm text-slate-500">{trip.route.origin.name} to {trip.route.destination.name} · {trip.bus.model}</p><div className="mt-4 flex flex-wrap gap-2">{trip.bus.amenities?.map((amenity) => <span key={amenity} className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">{amenity.replaceAll("_", " ")}</span>)}</div></div><div className="flex min-w-36 flex-col items-end justify-between gap-4"><div className="text-right"><p className="text-xs text-slate-500">from</p><p className="text-3xl font-black">{trip.currency} {trip.base_fare}</p><p className="text-xs text-[#16796f]">{trip.available_seats} seats left</p></div><button onClick={choose} className="rounded-xl bg-[#0c312d] px-5 py-3 text-sm font-bold text-white">Choose seats</button></div></div></article>; }
function time(value: string) { return new Intl.DateTimeFormat(undefined, { hour: "2-digit", minute: "2-digit" }).format(new Date(value)); }
function duration(minutes: number) { return `${Math.floor(minutes / 60)}h ${minutes % 60}m`; }
function firstError(body: { message?: string; errors?: Record<string, string[]> }) { return Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? "Something went wrong."; }
