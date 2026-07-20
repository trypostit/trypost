FROM node:20-bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      git \
      python3 \
      python3-pip \
      ca-certificates \
      curl \
      jq \
      rsync \
 && rm -rf /var/lib/apt/lists/*

RUN npm install -g @anthropic-ai/claude-code

RUN useradd -ms /bin/bash evaluator \
 && mkdir -p /workspace /project /output /home/evaluator/.claude \
 && chown -R evaluator:evaluator /workspace /output /home/evaluator

USER evaluator
WORKDIR /workspace

ENV HOME=/home/evaluator
ENV CLAUDE_CONFIG_DIR=/home/evaluator/.claude
ENV PATH=/home/evaluator/.local/bin:$PATH

CMD ["bash"]
