import SignatureCard from '../components/SignatureCard'

const t = {
  signature: {
    upLabel: 'Upvote',
    downLabel: 'Downvote',
    hint: 'Click to vote',
    eyebrow: 'Trending',
    idea: 'Add Dark Mode Support',
    consensusValue: '92%',
    consensusLabel: 'consensus',
    consensusLabelLow: 'contested',
    status: 'In Progress',
    caption:
      'Real votes drive real priorities. Up ⬆ and down ⬇ — every voice counts, every click shapes the roadmap.',
  },
}

export default function LandingEn() {
  return (
    <div className="vp-page relative z-10 min-h-dvh flex flex-col">
      {/* Header */}
      <header className="py-5 px-6 flex items-center justify-between max-w-[72rem] mx-auto w-full">
        <a
          href="/"
          className="vp-brand inline-flex items-center gap-[.04em] no-underline font-archivo font-extrabold -tracking-[.02em] text-[1.75rem] leading-none"
        >
          <span className="text-[#15161A]">Vote</span>
          <span className="text-[#D8503C]">pit</span>
        </a>
        <div className="flex items-center gap-[.6rem]">
          <div className="vp-lang inline-flex p-[3px] gap-[2px] bg-[rgba(21,22,26,.05)] border border-[rgba(21,22,26,0.1)] rounded-[10px]">
            <span className="block font-semibold text-[.8rem] leading-none text-[#15161A] py-[.35rem] px-[.6rem] rounded-[7px] bg-white shadow-[0_1px_2px_rgba(21,22,26,0.12)]">
              EN
            </span>
            <a
              href="/de"
              className="block font-semibold text-[.8rem] leading-none text-[#979BA3] no-underline py-[.35rem] px-[.6rem] rounded-[7px] hover:text-[#15161A]"
            >
              DE
            </a>
          </div>
          <a
            href="https://github.com/silvio-l/votepit"
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-[.4rem] whitespace-nowrap font-semibold text-[.9rem] text-[#15161A] no-underline py-[.4rem] px-[.8rem] border border-[rgba(21,22,26,0.1)] rounded-[10px] hover:bg-[rgba(21,22,26,.05)]"
          >
            <svg width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
              <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z" />
            </svg>
            <span className="vp-gh-text">GitHub</span>
          </a>
        </div>
      </header>

      {/* Hero */}
      <section className="flex-1 flex items-center justify-center text-center py-[clamp(2.5rem,6vw,5rem)] px-5">
        <div className="w-full max-w-[44rem] min-w-0">
          <h1 className="font-archivo font-extrabold text-[clamp(1.5rem,7vw,4rem)] leading-[1.05] -tracking-[.03em] text-[#15161A] mb-4 break-words text-balance">
            Your users know what to build. Give them a voice.
          </h1>
          <p className="text-[clamp(1rem,2.4vw,1.2rem)] text-[#565A62] mb-9 max-w-[34rem] mx-auto leading-relaxed">
            Self-hosted feature voting with real up- and down-votes. One click install on any
            PHP/MySQL webspace.
          </p>
          <a
            href="https://github.com/silvio-l/votepit"
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-2 py-[.8rem] px-[1.9rem] bg-[#15161A] text-white rounded-[10px] font-semibold text-base no-underline hover:opacity-90 hover:-translate-y-px transition-all"
          >
            View on GitHub
          </a>
        </div>
      </section>

      {/* Signature Card */}
      <SignatureCard t={t} />

      {/* Features */}
      <section className="max-w-[64rem] mx-auto py-[clamp(2rem,6vw,4.5rem)] px-5">
        <h2 className="text-center font-archivo font-extrabold text-[clamp(1.5rem,4vw,2.2rem)] -tracking-[.02em] text-[#15161A] mb-8">
          Everything you need, nothing you don't.
        </h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {[
            {
              title: 'Real Down-Votes',
              desc: 'Up AND down. The only OSS board that captures real consensus – not just enthusiasm.',
            },
            {
              title: 'Self-Hosted & Free',
              desc: 'Runs on any PHP/MySQL webspace. No Docker, no SaaS lock-in, MIT license.',
            },
            {
              title: 'Magic-Link Login',
              desc: 'No passwords. Users sign in with a one-click link sent to their email.',
            },
            {
              title: 'Duplicate Detection',
              desc: 'As-you-type suggestions prevent double submissions before they happen.',
            },
            {
              title: 'Multi-Board',
              desc: 'One installation serves unlimited project boards – each with its own branding.',
            },
            {
              title: 'Admin Moderation',
              desc: 'Status transitions, editing, blocking. Full control over every board.',
            },
          ].map((f) => (
            <div
              key={f.title}
              className="bg-white/72 border border-white/55 rounded-[16px] p-[1.4rem] shadow-[0_0_0_1px_rgba(21,22,26,0.1),0_10px_30px_-18px_rgba(21,22,26,0.12)] backdrop-blur-[14px] saturate-[1.2] hover:-translate-y-[3px] hover:shadow-[0_0_0_1px_rgba(21,22,26,0.1),0_18px_42px_-22px_rgba(21,22,26,0.12)] transition-all"
            >
              <span className="inline-flex text-[#0E9466] mb-[.7rem]">
                <svg
                  width="20"
                  height="20"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  aria-hidden="true"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                  <polyline points="22 4 12 14.01 9 11.01" />
                </svg>
              </span>
              <h3 className="font-archivo font-extrabold text-[1.05rem] -tracking-[.01em] text-[#15161A] mb-1">
                {f.title}
              </h3>
              <p className="text-[.92rem] text-[#565A62] leading-relaxed">{f.desc}</p>
            </div>
          ))}
        </div>
      </section>

      {/* Install Block */}
      <section className="max-w-[40rem] mx-auto py-[clamp(1rem,4vw,2rem)] px-5 pb-[clamp(2.5rem,6vw,4rem)] text-center">
        <h2 className="font-archivo font-extrabold text-[clamp(1.3rem,3.5vw,1.8rem)] -tracking-[.02em] text-[#15161A] mb-1">
          Install in 60 seconds
        </h2>
        <p className="text-[#565A62] mb-6 text-[.98rem]">
          Upload via FTP, open your browser, done.
        </p>
        <div className="text-left">
          <span className="block text-[.72rem] font-semibold tracking-[.06em] uppercase text-[#979BA3] mb-2">
            Composer
          </span>
          <div className="flex items-stretch gap-2 bg-[#15161A] rounded-[10px] py-2 px-[.9rem] shadow-[0_14px_34px_-18px_rgba(21,22,26,0.12)]">
            <code className="flex-1 self-center font-mono text-[.86rem] text-[#e9e9e7] overflow-x-auto whitespace-nowrap">
              <span className="text-[#0E9466] select-none mr-2">$</span>
              composer create-project votepit/votepit
            </code>
            <button
              type="button"
              className="shrink-0 cursor-pointer border-0 font-semibold text-[.8rem] text-[#15161A] bg-white rounded-[7px] py-[.45rem] px-[.85rem] hover:bg-[#f1f1ee]"
              onClick={() =>
                navigator.clipboard.writeText('composer create-project votepit/votepit')
              }
            >
              Copy
            </button>
          </div>
          <p className="mt-[1.1rem] text-[.88rem] text-[#565A62] leading-relaxed">
            Or <strong className="text-[#15161A]">download the ZIP</strong> from GitHub, upload to
            your webspace, and run the browser installer.
          </p>
        </div>
      </section>

      {/* Footer */}
      <footer className="py-7 px-6 text-center text-[.85rem] text-[#565A62] border-t border-[rgba(21,22,26,0.1)] flex flex-col gap-[.35rem]">
        <p className="mb-0">Built by Silvio &middot; MIT License</p>
        <p className="mb-0 inline-flex gap-2 justify-center items-center flex-wrap">
          <a
            href="/privacy"
            className="text-[#15161A] font-semibold no-underline border-b border-[rgba(21,22,26,0.1)] hover:text-[#D8503C] hover:border-current"
          >
            Privacy
          </a>
          <span className="text-[rgba(21,22,26,0.1)]">·</span>
          <a
            href="/legal-notice"
            className="text-[#15161A] font-semibold no-underline border-b border-[rgba(21,22,26,0.1)] hover:text-[#D8503C] hover:border-current"
          >
            Legal Notice
          </a>
          <span className="text-[rgba(21,22,26,0.1)]">·</span>
          <a
            href="https://github.com/silvio-l/votepit"
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-[.4rem] whitespace-nowrap text-[#15161A] font-semibold no-underline border-b border-[rgba(21,22,26,0.1)] hover:text-[#D8503C] hover:border-current"
          >
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
              <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z" />
            </svg>
            GitHub
          </a>
        </p>
      </footer>
    </div>
  )
}
