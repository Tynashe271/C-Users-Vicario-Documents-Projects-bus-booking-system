import { ModuleWorkspace } from "@/components/module-workspace";

const modules = [
  ["trip_staff", "Assigned trips", "Route, vehicle, crew and departure"], ["pre_trip_checklists", "Pre-trip checks", "Vehicle, fuel, safety, documents and GPS"], ["boarding_scans", "Boarding scans", "Online QR ticket verification"], ["manual_boarding_verifications", "Manual checks", "Booking reference and passenger lookup"], ["offline_sync_batches", "Offline boarding", "Synchronise checks captured without connectivity"], ["trip_status_updates", "Trip status", "Boarding, departure, delay and arrival"], ["incidents", "Incidents", "Breakdowns, delays and safety reports"],
].map(([key, label, description]) => ({ key, label, description }));

export default function CrewOperationsPage() { return <ModuleWorkspace eyebrow="Mufambi crew" title="Driver & conductor operations" description="Access assigned company trips, complete safety checks, verify boarding, sync offline activity and report trip status or incidents." modules={modules} />; }
