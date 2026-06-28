const TOP  = "M 165.0 0.0 L 165.0 -44.0 Q 165.0 -72.0 141.6 -87.3 L 23.4 -164.7 Q 0.0 -180.0 -23.4 -164.7 L -141.6 -87.3 Q -165.0 -72.0 -165.0 -44.0 L -165.0 0.0 Z"
const BOT  = "M -165.0 0.0 L -165.0 44.0 Q -165.0 72.0 -141.6 87.3 L -23.4 164.7 Q 0.0 180.0 23.4 164.7 L 141.6 87.3 Q 165.0 72.0 165.0 44.0 L 165.0 0.0 Z"
const MID  = "M -15.9 -112.0 Q 0.0 -122.4 15.9 -112.0 L 96.3 -59.4 Q 112.2 -49.0 112.2 -29.9 L 112.2 29.9 Q 112.2 49.0 96.3 59.4 L 15.9 112.0 Q 0.0 122.4 -15.9 112.0 L -96.3 59.4 Q -112.2 49.0 -112.2 29.9 L -112.2 -29.9 Q -112.2 -49.0 -96.3 -59.4 Z"
const DARK = "M -11.7 -82.3 Q 0.0 -90.0 11.7 -82.3 L 70.8 -43.7 Q 82.5 -36.0 82.5 -22.0 L 82.5 22.0 Q 82.5 36.0 70.8 43.7 L 11.7 82.3 Q 0.0 90.0 -11.7 82.3 L -70.8 43.7 Q -82.5 36.0 -82.5 22.0 L -82.5 -22.0 Q -82.5 -36.0 -70.8 -43.7 Z"

export default function BrandBackdrop() {
  return (
    <div className="vp-backdrop" aria-hidden="true">
      <svg className="vp-hex vp-hex--tr" viewBox="-185 -205 370 410" width="540" height="599" fill="none">
        <g className="vp-hex-g">
          <path className="vp-h vp-h--top"  d={TOP}  fill="#0E9466" />
          <path className="vp-h vp-h--bot"  d={BOT}  fill="#D8503C" />
          <path className="vp-h vp-h--mid"  d={MID}  fill="#084C37" />
          <path className="vp-h vp-h--dark" d={DARK} fill="#05241A" />
        </g>
      </svg>
      <svg className="vp-hex vp-hex--bl" viewBox="-185 -205 370 410" width="380" height="421" fill="none">
        <g className="vp-hex-g">
          <path className="vp-h vp-h--top"  d={TOP}  fill="#0E9466" />
          <path className="vp-h vp-h--bot"  d={BOT}  fill="#D8503C" />
          <path className="vp-h vp-h--mid"  d={MID}  fill="#084C37" />
          <path className="vp-h vp-h--dark" d={DARK} fill="#05241A" />
        </g>
      </svg>
      <style>{`
        .vp-backdrop {
          position: fixed; inset: 0; overflow: hidden;
          pointer-events: none; z-index: 0;
        }
        .vp-hex { position: absolute; }
        .vp-hex--tr { top: -150px; right: -120px; opacity: .05; }
        .vp-hex--bl { bottom: -140px; left: -130px; opacity: .038; }
        @media (prefers-reduced-motion: no-preference) {
          .vp-h { opacity: 0; transform-box: fill-box; transform-origin: 50% 50%; }
          .vp-h--top  { animation: vp-cy-down 20s ease-in-out 0s    infinite; }
          .vp-h--bot  { animation: vp-cy-up   20s ease-in-out .6s   infinite; }
          .vp-h--mid  { animation: vp-cy-pop  20s ease-in-out 1.0s  infinite; }
          .vp-h--dark { animation: vp-cy-pop  20s ease-in-out 1.3s  infinite; }
          .vp-hex--bl .vp-h--top  { animation-delay: 10s; }
          .vp-hex--bl .vp-h--bot  { animation-delay: 10.6s; }
          .vp-hex--bl .vp-h--mid  { animation-delay: 11s; }
          .vp-hex--bl .vp-h--dark { animation-delay: 11.3s; }
          @keyframes vp-cy-down {
            0%   { opacity: 0; transform: translate(-30px, -98px) scale(.95); }
            14%  { opacity: 1; transform: none; }
            64%  { opacity: 1; transform: none; }
            84%  { opacity: 0; transform: translate(-30px, -98px) scale(.95); }
            100% { opacity: 0; transform: translate(-30px, -98px) scale(.95); }
          }
          @keyframes vp-cy-up {
            0%   { opacity: 0; transform: translate(30px, 98px) scale(.95); }
            14%  { opacity: 1; transform: none; }
            64%  { opacity: 1; transform: none; }
            84%  { opacity: 0; transform: translate(30px, 98px) scale(.95); }
            100% { opacity: 0; transform: translate(30px, 98px) scale(.95); }
          }
          @keyframes vp-cy-pop {
            0%   { opacity: 0; transform: scale(.08); }
            14%  { opacity: 1; transform: none; }
            64%  { opacity: 1; transform: none; }
            84%  { opacity: 0; transform: scale(.08); }
            100% { opacity: 0; transform: scale(.08); }
          }
          .vp-hex-g { transform-box: fill-box; transform-origin: 50% 50%; animation: vp-h-float 26s ease-in-out infinite; }
          .vp-hex--bl .vp-hex-g { animation-duration: 32s; animation-direction: reverse; }
          @keyframes vp-h-float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50%      { transform: translateY(-16px) rotate(1.9deg); }
          }
        }
      `}</style>
    </div>
  )
}
