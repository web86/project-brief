import { spawnSync } from 'node:child_process'
import { validateStaticDemo } from './validate-static-demo.mjs'
const result = spawnSync('bin/demo', ['export'], { stdio: 'inherit' })
if (result.status !== 0) process.exit(result.status || 1)
const { files, manifest, ...report } = await validateStaticDemo('.tools/demo-runtime/static-export')
console.log(JSON.stringify(report, null, 2))
