<script setup>
import { computed } from 'vue'
import { t } from '../i18n/index.js'
import { setUnlimited } from '../services/planManagement.js'
const props = defineProps({
  definitions: { type: Array, required: true },
  configuration: { type: Object, required: true },
})
const groups = computed(() => {
  const category = (key) =>
    key.startsWith('projects.') || key.startsWith('developers.')
      ? 'projects'
      : key.startsWith('clients.')
        ? 'clients'
        : key.startsWith('agents.') || key === 'api.agents.enabled'
          ? 'agents'
          : key.startsWith('storage.')
            ? 'storage'
            : key.startsWith('branding.')
              ? 'branding'
              : ['api.', 'webhooks.', 'sso.'].some((prefix) => key.startsWith(prefix))
                ? 'integrations'
                : 'additional'
  return ['projects', 'clients', 'agents', 'integrations', 'branding', 'storage', 'additional']
    .map((key) => ({
      key,
      definitions: props.definitions.filter((definition) => category(definition.key) === key),
    }))
    .filter((group) => group.definitions.length)
})
const item = (key) => props.configuration.entitlements.find((entry) => entry.key === key)
const label = (key) => t('planManagement.featureNames.' + key)
</script>
<template>
  <div class="plans-entitlement-groups">
    <section v-for="group in groups" :key="group.key">
      <h3>{{ t('planManagement.groups.' + group.key) }}</h3>
      <div class="plans-feature-grid">
        <article
          v-for="definition in group.definitions"
          :key="definition.key"
          class="plans-feature surface"
        >
          <h3>{{ label(definition.key) }}</h3>
          <small>{{ definition.key }}</small>
          <template v-if="definition.derived">
            <p>
              {{ t('planManagement.derived') }}:
              <strong>{{ configuration.developerSeats.included }}</strong>
            </p>
          </template>
          <template v-else-if="definition.availability !== 'available'">
            <p>
              {{ t('planManagement.states.' + definition.availability) }} ·
              {{ t('planManagement.unconfigured') }}
            </p>
            <small>{{ t('planManagement.plannedHint') }}</small>
          </template>
          <template v-else-if="item(definition.key)">
            <label v-if="definition.allow_unlimited" class="plans-check">
              <input
                type="checkbox"
                :checked="item(definition.key).value.kind === 'unlimited'"
                @change="setUnlimited(item(definition.key), definition, $event.target.checked)"
              />
              {{ t('planManagement.unlimited') }}
            </label>
            <label v-if="definition.kind === 'boolean'" class="plans-check">
              <input
                v-model="item(definition.key).value.value"
                type="checkbox"
                :aria-label="label(definition.key)"
              />{{ label(definition.key) }}
            </label>
            <label
              v-else-if="
                definition.kind === 'integer' && item(definition.key).value.kind !== 'unlimited'
              "
            >
              {{ label(definition.key)
              }}<input
                v-model.number="item(definition.key).value.value"
                type="number"
                min="0"
                max="9007199254740991"
                step="1"
                required
              />
            </label>
            <label v-else-if="definition.kind === 'enum'">
              {{ label(definition.key)
              }}<select v-model="item(definition.key).value.value">
                <option v-for="value in definition.values" :key="value" :value="value">
                  {{ t('planManagement.enumNames.' + value) }}
                </option>
              </select>
            </label>
            <template
              v-else-if="
                definition.kind === 'quota' && item(definition.key).value.kind !== 'unlimited'
              "
            >
              <label
                >{{ t('planManagement.quotaLimit')
                }}<input
                  v-model.number="item(definition.key).value.limit"
                  type="number"
                  min="0"
                  step="1"
                  required
              /></label>
              <label
                >{{ t('planManagement.quotaWindow')
                }}<select v-model="item(definition.key).value.window">
                  <option v-for="window in definition.windows" :key="window">{{ window }}</option>
                </select></label
              >
            </template>
            <ul v-if="definition.sections_by_level">
              <li v-for="level in definition.values" :key="level">
                <strong>{{ t('planManagement.enumNames.' + level) }}</strong> ·
                {{ t('brandingLevels.' + level) }}
              </li>
            </ul>
          </template>
        </article>
      </div>
    </section>
  </div>
</template>
