"use client";

import { FormEvent, useEffect, useRef, useState } from "react";
import { usePathname } from "next/navigation";
import { readAccessToken } from "@/lib/auth-token";

type Message = {
  role: "assistant" | "user";
  content: string;
};

const welcomeMessage: Message = {
  role: "assistant",
  content: "Hi! I’m Mufambi Assist. I can help with booking, payments, luggage, and tracking your journey. What do you need?",
};

const suggestions = ["How do I book?", "What can I take?", "How do payments work?"];

export function TravelAssistant() {
  const pathname = usePathname();
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [isOpen, setIsOpen] = useState(false);
  const [messages, setMessages] = useState<Message[]>([welcomeMessage]);
  const [input, setInput] = useState("");
  const [isSending, setIsSending] = useState(false);
  const messagesEndRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    queueMicrotask(() => setIsAuthenticated(Boolean(readAccessToken())));
  }, [pathname]);

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages, isSending]);

  async function sendMessage(event: FormEvent<HTMLFormElement> | null, suggestedMessage?: string) {
    event?.preventDefault();
    const content = (suggestedMessage ?? input).trim();

    if (!content || isSending) {
      return;
    }

    const nextMessages = [...messages, { role: "user" as const, content }];
    setMessages(nextMessages);
    setInput("");
    setIsSending(true);

    try {
      const response = await fetch("/api/assistant", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ messages: nextMessages.slice(-10) }),
      });
      const body = (await response.json()) as { message?: string };

      if (!response.ok || !body.message) {
        throw new Error(body.message);
      }

      setMessages((current) => [...current, { role: "assistant", content: body.message! }]);
    } catch {
      setMessages((current) => [
        ...current,
        {
          role: "assistant",
          content: "I’m having trouble connecting right now. You can still use the Help section or try again in a moment.",
        },
      ]);
    } finally {
      setIsSending(false);
    }
  }

  if (!isAuthenticated) {
    return null;
  }

  return (
    <div className="fixed bottom-5 right-5 z-50 flex flex-col items-end gap-3 sm:bottom-7 sm:right-7">
      {isOpen && (
        <section
          aria-label="Mufambi AI assistant"
          className="flex h-[min(36rem,calc(100dvh-7rem))] w-[min(24rem,calc(100vw-2.5rem))] flex-col overflow-hidden rounded-3xl border border-black/10 bg-[#f8f7f3] shadow-2xl shadow-slate-950/25"
        >
          <header className="flex items-center justify-between bg-[#0c312d] px-5 py-4 text-white">
            <div className="flex items-center gap-3">
              <span className="grid size-10 place-items-center rounded-full bg-[#ffce54] text-[#0c312d]" aria-hidden="true">
                <SparkIcon />
              </span>
              <div>
                <h2 className="font-black">Mufambi Assist</h2>
                <p className="flex items-center gap-1.5 text-xs text-emerald-100/75"><span className="size-1.5 rounded-full bg-emerald-300" />AI travel helper</p>
              </div>
            </div>
            <button type="button" onClick={() => setIsOpen(false)} aria-label="Close assistant" className="grid size-9 place-items-center rounded-full text-xl text-white/70 transition hover:bg-white/10 hover:text-white">×</button>
          </header>

          <div aria-live="polite" className="flex flex-1 flex-col gap-3 overflow-y-auto px-4 py-5">
            {messages.map((message, index) => (
              <div key={`${message.role}-${index}`} className={`max-w-[86%] whitespace-pre-wrap rounded-2xl px-4 py-3 text-sm leading-6 ${message.role === "user" ? "ml-auto rounded-br-md bg-[#ef5b35] text-white" : "rounded-bl-md border border-black/5 bg-white text-slate-700 shadow-sm"}`}>
                {message.content}
              </div>
            ))}
            {messages.length === 1 && <div className="flex flex-wrap gap-2">{suggestions.map((suggestion) => <button key={suggestion} type="button" onClick={() => sendMessage(null, suggestion)} className="rounded-full border border-[#16796f]/25 bg-emerald-50 px-3 py-2 text-xs font-semibold text-[#11645c] transition hover:border-[#16796f]">{suggestion}</button>)}</div>}
            {isSending && <div className="flex w-fit items-center gap-1 rounded-2xl rounded-bl-md bg-white px-4 py-4 shadow-sm" aria-label="Assistant is thinking"><span className="size-1.5 animate-bounce rounded-full bg-[#16796f]" /><span className="size-1.5 animate-bounce rounded-full bg-[#16796f] [animation-delay:120ms]" /><span className="size-1.5 animate-bounce rounded-full bg-[#16796f] [animation-delay:240ms]" /></div>}
            <div ref={messagesEndRef} />
          </div>

          <form onSubmit={sendMessage} className="border-t border-black/10 bg-white p-3">
            <div className="flex items-end gap-2 rounded-2xl bg-slate-100 p-2 pl-4">
              <label htmlFor="assistant-message" className="sr-only">Ask Mufambi Assist</label>
              <textarea id="assistant-message" rows={1} maxLength={500} value={input} onChange={(event) => setInput(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter" && !event.shiftKey) { event.preventDefault(); event.currentTarget.form?.requestSubmit(); } }} placeholder="Ask about your journey…" className="max-h-24 min-h-10 flex-1 resize-none bg-transparent py-2 text-sm text-slate-900 outline-none placeholder:text-slate-400" />
              <button disabled={!input.trim() || isSending} aria-label="Send message" className="grid size-10 shrink-0 place-items-center rounded-xl bg-[#ef5b35] text-white transition hover:bg-[#d94b29] disabled:cursor-not-allowed disabled:opacity-40"><SendIcon /></button>
            </div>
            <p className="mt-2 text-center text-[10px] text-slate-400">AI can make mistakes. Confirm important travel details.</p>
          </form>
        </section>
      )}

      <button type="button" onClick={() => setIsOpen((current) => !current)} aria-expanded={isOpen} aria-label={isOpen ? "Close Mufambi Assist" : "Open Mufambi Assist"} className="group flex items-center gap-3 rounded-full bg-[#0c312d] p-2 pr-5 text-white shadow-xl shadow-slate-950/25 transition hover:-translate-y-0.5 hover:bg-[#12443e] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#ef5b35]">
        <span className="grid size-11 place-items-center rounded-full bg-[#ffce54] text-[#0c312d] transition group-hover:rotate-6" aria-hidden="true"><SparkIcon /></span>
        <span className="text-left"><span className="block text-xs text-emerald-100/70">Need help?</span><span className="block text-sm font-black">Ask Mufambi</span></span>
      </button>
    </div>
  );
}

function SparkIcon() {
  return <svg viewBox="0 0 24 24" fill="none" className="size-5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="m12 3 1.4 4.1L17.5 8.5l-4.1 1.4L12 14l-1.4-4.1-4.1-1.4 4.1-1.4L12 3Z" /><path d="m18.5 14 .8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8.8-2.2Z" /></svg>;
}

function SendIcon() {
  return <svg viewBox="0 0 24 24" fill="none" className="size-5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="m22 2-7 20-4-9-9-4Z" /><path d="M22 2 11 13" /></svg>;
}
