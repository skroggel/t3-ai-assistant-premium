<template>
    <AiAssistantSearchSummary v-bind="props" :chat-component="AiAssistantChat" />
</template>

<script setup>
import { summaryProps } from '../summaryProps.js';
import AiAssistantChat from '../../../../../../../t3-ai-assistant/Resources/Private/Frontend/Vue/Src/components/AiAssistantChat.vue';
import AiAssistantSearchSummary from './AiAssistantSearchSummary.vue';

/** Framework-independent adapter around the standard Vue Summary component. */
const props = defineProps(summaryProps);
</script>
