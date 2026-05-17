from pathlib import Path

p = Path(__file__).resolve().parents[1] / "paxdesign-toolbar/assets/js/dock.js"
c = p.read_text(encoding="utf-8")
start = c.find("function renderPhishingIntelHero")
end = c.find("applyDockModuleIcons()", start)
if start < 0 or end < 0:
    raise SystemExit("markers not found")
block = c[start:end]
# Invalid HTML tag used during edit: "motion.div" (framer-motion typo) -> "motion.div"
block = block.replace("motion.div", "motion.div")
block = block.replace("motion.div", "div")
# Fix broken closing replace line
import re
block = re.sub(
    r"return html\.replace\([^;]+\);",
    "return html;",
    block,
    count=1,
)
p.write_text(c[:start] + block + c[end:], encoding="utf-8", newline="\n")
print("patched", p)
