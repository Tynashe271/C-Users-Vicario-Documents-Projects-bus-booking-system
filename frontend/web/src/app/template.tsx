import { BackButton } from "@/components/back-button";

export default function Template({ children }: { children: React.ReactNode }) {
  return <div className="site-page-transition min-h-dvh flex-1">
    <BackButton />
    {children}
  </div>;
}
