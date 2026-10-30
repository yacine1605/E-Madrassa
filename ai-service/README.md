# AI Quiz Generator Microservice

Proof-of-concept backend service for Madrassa School Management Platform, powered by **LangChain** and **Groq (`llama-3.3-70b-versatile`)** using strict structured outputs (`.with_structured_output()`).

## 1. Quick Setup

```bash
cd ai-service
pip install -r requirements.txt
cp .env.example .env
```

Edit `.env` and provide your Groq API key:
```env
GROQ_API_KEY=gsk_your_groq_api_key_here
```

## 2. Start the Service

```bash
python quiz_service.py
```
By default, the service listens on `http://127.0.0.1:8001`.

## 3. Test CLI Output Directly

You can test the generation function directly with Python:
```bash
python -c "from quiz_service import generate_school_quiz; quiz = generate_school_quiz('Math', '3AM', 'Théorème de Pythagore', 3); print(quiz.model_dump_json(indent=2))"
```

## 4. API Endpoints

- **`POST /generate-quiz`**:
  Request body:
  ```json
  {
    "subject": "Computer Science",
    "grade_level": "3rd Year University",
    "topic": "Graph Traversal (BFS & DFS)",
    "num_questions": 3
  }
  ```
  Response:
  ```json
  {
    "title": "Computer Science Assessment: Graph Traversal",
    "questions": [
      {
        "question": "Which data structure is typically used to implement Breadth-First Search (BFS)?",
        "options": ["Stack", "Queue", "Priority Queue", "Binary Search Tree"],
        "correct_index": 1,
        "explanation": "BFS explores nodes level by level using a FIFO Queue structure."
      }
    ]
  }
  ```

- **`GET /health`**: Healthcheck endpoint returning `{"status": "ok"}`.

## 5. Frontend Integration

In the Filament panel, navigate to **Pédagogie** > **Générateur de quiz IA** (`/admin/ai-quiz-generator`).
Configure:
- `AI_QUIZ_URL=http://127.0.0.1:8001` (or `http://host.docker.internal:8001` when Filament is in Docker).

