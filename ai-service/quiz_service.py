"""
Madrassa AI Quiz Service
========================
A modular FastAPI microservice that generates curriculum-aligned multiple-choice
quizzes using LangChain + Groq (Llama 3.3 70B) with structured output.

Start:
    python quiz_service.py          # runs on http://127.0.0.1:8001

Env vars:
    GROQ_API_KEY   – required, your Groq Cloud API key
    QUIZ_PORT      – optional, default 8001
"""

import os
from typing import List

from dotenv import load_dotenv
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from langchain_groq import ChatGroq
from langchain_core.prompts import ChatPromptTemplate

load_dotenv()  # reads .env in the working directory

# ── Pydantic schemas (strict so the UI never breaks during the demo) ────────

class QuestionItem(BaseModel):
    """A single multiple-choice question."""
    question: str = Field(description="The clear, curriculum-aligned question text")
    options: List[str] = Field(description="Exactly 4 multiple-choice options")
    correct_index: int = Field(description="0-based index (0 to 3) of the correct option")
    explanation: str = Field(
        description="1-sentence pedagogical explanation for the teacher/student"
    )


class GeneratedQuiz(BaseModel):
    """The complete quiz returned by the LLM."""
    title: str = Field(description="Formal title for the assessment")
    questions: List[QuestionItem]


class QuizRequest(BaseModel):
    """Incoming request from the frontend."""
    subject: str
    grade_level: str
    topic: str
    num_questions: int = Field(default=3, ge=3, le=5)


# ── Core generation function ────────────────────────────────────────────────

def generate_school_quiz(
    subject: str,
    grade_level: str,
    topic: str,
    num_questions: int = 3,
) -> GeneratedQuiz:
    """
    Build a LangChain chain that forces Groq / Llama 3.3 70B to return
    a ``GeneratedQuiz`` Pydantic object via ``.with_structured_output()``.
    """
    llm = ChatGroq(
        model="llama-3.3-70b-versatile",
        temperature=0.2,
    )

    structured_llm = llm.with_structured_output(GeneratedQuiz)

    prompt = ChatPromptTemplate.from_messages([
        ("system", (
            "You are an academic assessment engine integrated into a school "
            "management platform. Generate accurate, clear multiple-choice "
            "questions tailored to the specified grade level. Each question "
            "must have exactly 4 options. Return the quiz as structured JSON."
        )),
        ("human", (
            "Create a {num_questions}-question quiz for {grade_level} students "
            "in {subject}. Focus specifically on the lesson topic: '{topic}'."
        )),
    ])

    chain = prompt | structured_llm

    return chain.invoke({
        "subject": subject,
        "grade_level": grade_level,
        "topic": topic,
        "num_questions": num_questions,
    })


# ── FastAPI application ─────────────────────────────────────────────────────

app = FastAPI(title="Madrassa AI Quiz Service", version="1.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["POST", "GET"],
    allow_headers=["*"],
)


@app.post("/generate-quiz", response_model=GeneratedQuiz)
def api_generate_quiz(req: QuizRequest):
    """Generate a quiz and return the structured JSON response."""
    try:
        quiz = generate_school_quiz(
            req.subject, req.grade_level, req.topic, req.num_questions,
        )
        return quiz.model_dump()
    except Exception as exc:
        raise HTTPException(status_code=502, detail=str(exc))


@app.get("/health")
def health():
    return {"status": "ok"}


# ── CLI entrypoint ──────────────────────────────────────────────────────────

if __name__ == "__main__":
    import uvicorn

    port = int(os.getenv("QUIZ_PORT", "8001"))
    print(f"\n  Madrassa AI Quiz Service -> http://127.0.0.1:{port}\n")
    uvicorn.run(app, host="127.0.0.1", port=port)
