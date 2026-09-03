type ChatMessage = {
  role: "assistant" | "user";
  content: string;
};

type OpenAIResponse = {
  output?: Array<{
    type?: string;
    content?: Array<{ type?: string; text?: string }>;
  }>;
};

const assistantInstructions = `You are Mufambi Assist, the concise and friendly travel helper for a bus-booking platform serving Southern Africa.
Help passengers understand how to search for trips, select seats, book, pay, manage tickets, track buses, and prepare luggage.
Never invent live schedules, fares, seat availability, booking status, payment status, or policies. Tell the passenger to use the relevant page when live account or trip data is needed.
Do not ask for passwords, full payment card details, one-time codes, or other secrets.
Keep answers under 120 words, use plain language, and ask at most one useful follow-up question.`;

export async function POST(request: Request) {
  const apiKey = process.env.OPENAI_API_KEY;

  if (!apiKey) {
    return Response.json({ message: "The AI assistant has not been configured yet." }, { status: 503 });
  }

  let payload: { messages?: ChatMessage[] };

  try {
    payload = await request.json();
  } catch {
    return Response.json({ message: "Send a valid message." }, { status: 400 });
  }

  const messages = payload.messages?.filter((message) =>
    ["assistant", "user"].includes(message.role) && typeof message.content === "string" && message.content.trim().length > 0 && message.content.length <= 500,
  ).slice(-10);

  if (!messages?.length || messages.at(-1)?.role !== "user") {
    return Response.json({ message: "Send a message to the assistant." }, { status: 422 });
  }

  try {
    const response = await fetch("https://api.openai.com/v1/responses", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${apiKey}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        model: process.env.OPENAI_MODEL ?? "gpt-5-mini",
        instructions: assistantInstructions,
        input: messages,
        max_output_tokens: 300,
        store: false,
      }),
      signal: AbortSignal.timeout(20_000),
    });

    if (!response.ok) {
      return Response.json({ message: "The assistant is temporarily unavailable." }, { status: 502 });
    }

    const result = (await response.json()) as OpenAIResponse;
    const message = result.output
      ?.filter((item) => item.type === "message")
      .flatMap((item) => item.content ?? [])
      .filter((item) => item.type === "output_text")
      .map((item) => item.text)
      .join("\n")
      .trim();

    if (!message) {
      return Response.json({ message: "The assistant could not answer that. Please try again." }, { status: 502 });
    }

    return Response.json({ message });
  } catch {
    return Response.json({ message: "The assistant is temporarily unavailable." }, { status: 502 });
  }
}
