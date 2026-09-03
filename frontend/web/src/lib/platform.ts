export const platform = {
  apiUrl: "/api/v1",
  graphqlUrl: "/graphql",
  reverb: {
    appKey: process.env.NEXT_PUBLIC_REVERB_APP_KEY ?? "",
    host: process.env.NEXT_PUBLIC_REVERB_HOST ?? "localhost",
    port: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080),
    scheme: process.env.NEXT_PUBLIC_REVERB_SCHEME ?? "http",
  },
} as const;
