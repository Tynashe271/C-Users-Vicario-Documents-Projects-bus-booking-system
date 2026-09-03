export function readAccessToken(): string {
  if (typeof window === "undefined") return "";
  return localStorage.getItem("mufambi_access_token") ?? localStorage.getItem("mufambi-token") ?? "";
}

export function storeAccessToken(token: string): void {
  clearGuestSession();
  localStorage.setItem("mufambi_access_token", token);
  localStorage.setItem("mufambi-token", token);
}

export function clearAccessToken(): void {
  localStorage.removeItem("mufambi_access_token");
  localStorage.removeItem("mufambi-token");
}

export function hasGuestSession(): boolean {
  return typeof window !== "undefined" && sessionStorage.getItem("mufambi_guest_session") === "active";
}

export function startGuestSession(): void {
  clearAccessToken();
  sessionStorage.setItem("mufambi_guest_session", "active");
}

export function clearGuestSession(): void {
  if (typeof window !== "undefined") sessionStorage.removeItem("mufambi_guest_session");
}
