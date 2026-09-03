import { ModuleWorkspace } from "@/components/module-workspace";

const modules = [
  ["branches", "Branches", "Addresses, contacts and operating hours"], ["staff_assignments", "Branch assignments", "Managers, agents, buses and routes"], ["seat_layouts", "Seat layouts", "Standard, executive and sleeper plans"], ["route_stops", "Route stops", "Shared terminals, pickups and drop-offs"], ["schedules", "Schedules", "Daily, weekly, holiday and seasonal services"], ["fares", "Fares", "Adult, child, student, class and seasonal pricing"], ["trip_staff", "Trip assignments", "Driver and conductor allocation"], ["operator_policies", "Company policies", "Luggage, cancellation, rescheduling and boarding"], ["analytics_snapshots", "Company reports", "Revenue, occupancy, cancellations and performance"], ["audit_logs", "Company audit", "Company-owned changes and approvals"],
].map(([key, label, description]) => ({ key, label, description }));

export default function OperatorManagementPage() { return <ModuleWorkspace eyebrow="Mufambi operator" title="Company operations" description="Company-scoped branches, routes, schedules, fares, policies, assignments and reports." modules={modules} />; }
