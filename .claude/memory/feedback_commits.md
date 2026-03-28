---
name: no-ai-attribution-in-commits
description: Never add Co-Authored-By or any AI mention in git commit messages
type: feedback
---

Never include `Co-Authored-By: Claude ...` or any other AI attribution line in commit messages.

**Why:** User does not want AI mentions in the git history.

**How to apply:** All commits on this project — strip any `Co-Authored-By` or AI-related trailer lines before committing.
