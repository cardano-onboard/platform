<script setup>
/**
 * Who a batch of codes is being handed to, chosen while the batch is generated.
 *
 * "Unassigned" is one of the choices rather than an empty field. Leaving the control
 * blank and leaving it on Unassigned look the same to an operator, but only one of them
 * is a decision, and a batch generated with nobody in mind is a real answer that the
 * export and the report both have to show.
 *
 * Assignment happens here or nowhere: there is no way to attribute a code to a partner
 * after it has been handed out, because a code reassigned once its claims are in was not
 * necessarily given away by whoever it was moved to.
 */
import { computed } from "vue";

const UNASSIGNED = "__unassigned__";
const NEW = "__new__";

const props = defineProps({
    partners: {
        type: Array,
        default: () => [],
    },
    /** { id, name, kind }: an id for a partner that exists, a name for one being added. */
    modelValue: {
        type: Object,
        default: () => ({ id: null, name: null, kind: null }),
    },
    kinds: {
        type: Array,
        default: () => ["staff", "partner", "mailing", "print", "other"],
    },
    /**
     * "Partner" is the word the operator sees here, on the codes table and in the export.
     * One word for one thing: a control asking something the report does not answer under
     * the same name is a control nobody connects to the column they are reading.
     */
    label: {
        type: String,
        default: "Partner",
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    nameError: {
        type: [String, Array],
        default: () => [],
    },
});

const emit = defineEmits(["update:modelValue"]);

const options = computed(() => [
    { value: UNASSIGNED, title: "Unassigned" },
    ...props.partners.map((partner) => ({
        value: partner.id,
        title: partner.name,
    })),
    { value: NEW, title: "Add a partner" },
]);

const addingNew = computed(
    () => !props.modelValue?.id && props.modelValue?.name !== null && props.modelValue?.name !== undefined,
);

const selection = computed(() => {
    if (props.modelValue?.id) {
        return props.modelValue.id;
    }

    return addingNew.value ? NEW : UNASSIGNED;
});

function choose(value) {
    if (value === NEW) {
        emit("update:modelValue", { id: null, name: "", kind: null });

        return;
    }

    emit("update:modelValue", {
        id: value === UNASSIGNED ? null : value,
        name: null,
        kind: null,
    });
}

function setName(name) {
    emit("update:modelValue", {
        id: null,
        name,
        kind: props.modelValue?.kind ?? null,
    });
}

function setKind(kind) {
    emit("update:modelValue", {
        id: null,
        name: props.modelValue?.name ?? "",
        kind: kind ?? null,
    });
}
</script>

<template>
    <div>
        <v-select
            :items="options"
            :model-value="selection"
            :label="label"
            :disabled="disabled"
            item-title="title"
            item-value="value"
            density="comfortable"
            hide-details="auto"
            @update:model-value="choose"
        />

        <template v-if="addingNew">
            <v-text-field
                :model-value="modelValue.name"
                :disabled="disabled"
                :error-messages="nameError"
                label="Partner name"
                maxlength="80"
                counter="80"
                density="comfortable"
                class="mt-3"
                hide-details="auto"
                @update:model-value="setName"
            />

            <v-select
                :items="kinds"
                :model-value="modelValue.kind"
                :disabled="disabled"
                label="Type"
                density="comfortable"
                class="mt-3"
                clearable
                hide-details="auto"
                @update:model-value="setKind"
            />
        </template>
    </div>
</template>
