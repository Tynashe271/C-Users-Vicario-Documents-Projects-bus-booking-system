import { ModuleWorkspace } from "@/components/module-workspace";

const modules = [
  ["countries", "Countries", "Supported countries"], ["provinces", "Provinces", "Shared regions and provinces"], ["cities", "Cities", "Global city directory"], ["company_documents", "Company documents", "Operator compliance evidence"], ["payment_provider_reports", "Payments", "Provider monitoring and callbacks"], ["refunds", "Refunds", "Approval and completion"], ["settlements", "Settlements", "Operator payout batches"], ["commissions", "Commission", "Global and operator charges"], ["support_cases", "Support", "Platform support queues"], ["promotions", "Promotions", "Coupons, banners and campaigns"], ["review_moderations", "Review moderation", "Reported reviews and history"], ["system_settings", "System settings", "Currencies, locks, files and formats"], ["audit_logs", "Audit logs", "Security and operational changes"], ["report_exports", "Reports", "Platform exports"],
].map(([key, label, description]) => ({ key, label, description }));

export default function AdminManagementPage() { return <ModuleWorkspace eyebrow="Mufambi platform" title="Platform management" description="Permission-controlled management for locations, finance, support, content, settings and audit history." modules={modules} />; }
