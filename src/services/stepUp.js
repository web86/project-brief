import { shallowRef } from 'vue'
export const stepUpRequest = shallowRef(null)
let pending
export function requestStepUp() {
  if (pending) return pending
  pending = new Promise((resolve) => {
    stepUpRequest.value = (accepted) => {
      stepUpRequest.value = null
      pending = null
      resolve(accepted)
    }
  })
  return pending
}
