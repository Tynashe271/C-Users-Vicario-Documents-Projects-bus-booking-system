import { ModuleWorkspace } from "@/components/module-workspace";

const modules = [
  ["agents", "Agent profile", "Identity, approval and business details"], ["agent_wallets", "Wallet", "Available ticket-selling balance"], ["agent_deposits", "Deposits", "Wallet funding records"], ["agent_commissions", "Commission", "Earned and pending commission"], ["agent_statements", "Statements", "Sales and commission summaries"], ["agent_withdrawals", "Withdrawals", "Authorised payout requests"],
].map(([key, label, description]) => ({ key, label, description }));

export default function AgentPage() { return <ModuleWorkspace eyebrow="Mufambi agent" title="Agent sales workspace" description="Manage agent approval, wallet funding, commissions, statements and withdrawals. Trip sales use the shared booking flow." modules={modules} />; }
