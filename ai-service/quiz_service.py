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

env_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".env")
load_dotenv(env_path, override=True)  # reads .env in the script directory or parent

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
    Falls back gracefully to an available model on the account if
    llama-3.3-70b-versatile is not accessible on the current API key tier.
    """
    # Always reload dotenv so updating .env is picked up immediately
    load_dotenv(env_path, override=True)
    api_key = os.getenv("GROQ_API_KEY")

    if not api_key or api_key == "gsk_your_groq_api_key_here":
        raise ValueError("GROQ_API_KEY is not set. Please set your key in ai-service/.env")

    # Priority model list (llama-3.3-70b-versatile first, then fallback)
    preferred_model = os.getenv("GROQ_MODEL", "llama-3.3-70b-versatile")
    candidate_models = [preferred_model]
    for fallback in ["qwen/qwen3.8-27b", "openai/gpt-oss-120b", "openai/gpt-oss-20b"]:
        if fallback not in candidate_models:
            candidate_models.append(fallback)

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

    last_error = None
    for model_name in candidate_models:
        try:
            llm = ChatGroq(
                model=model_name,
                temperature=0.2,
                api_key=api_key,
            )
            structured_llm = llm.with_structured_output(GeneratedQuiz)
            chain = prompt | structured_llm

            return chain.invoke({
                "subject": subject,
                "grade_level": grade_level,
                "topic": topic,
                "num_questions": num_questions,
            })
        except Exception as e:
            err_str = str(e).lower()
            if "model_not_found" in err_str or "does not exist" in err_str:
                last_error = e
                continue
            raise e

    if last_error:
        raise last_error


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
