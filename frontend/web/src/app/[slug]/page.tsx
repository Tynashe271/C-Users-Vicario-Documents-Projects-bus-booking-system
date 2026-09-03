import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { PublicContentPage, publicPageAliases, publicPages, publicRouteSlugs, type PublicPageSlug } from "@/components/public-pages";

type PublicPageProps = { params: Promise<{ slug: string }> };

export function generateStaticParams() {
  return publicRouteSlugs.map((slug) => ({ slug }));
}

export async function generateMetadata({ params }: PublicPageProps): Promise<Metadata> {
  const requestedSlug = (await params).slug;
  const slug = publicPageAliases[requestedSlug as keyof typeof publicPageAliases] ?? requestedSlug as PublicPageSlug;
  const page = publicPages[slug];
  return page ? { title: page.title, description: page.description } : {};
}

export default async function PublicPage({ params }: PublicPageProps) {
  const { slug } = await params;
  if (!publicRouteSlugs.includes(slug)) notFound();
  const resolvedSlug = publicPageAliases[slug as keyof typeof publicPageAliases] ?? slug as PublicPageSlug;
  return <PublicContentPage slug={resolvedSlug} />;
}
