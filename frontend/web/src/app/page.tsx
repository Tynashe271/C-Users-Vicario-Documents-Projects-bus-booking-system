import { PublicLandingPage } from "@/components/public-pages";
import { LandingLoginGate } from "@/components/landing-login-gate";

export default function WelcomePage() {
  return <LandingLoginGate><PublicLandingPage /></LandingLoginGate>;
}
