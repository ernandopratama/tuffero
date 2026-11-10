{{-- Dropdown wilayah dengan pencarian. $field: province | district | city; $prefix: awalan id (co, ad); $words: teks checkout. Logika di resources/js/store/addressPicker.js. --}}
<div class="tf-auth-field tf-combo" :class="fieldClass('{{ $field }}')" @click.outside="closeCombo('{{ $field }}')">
	<label for="{{ $prefix }}-{{ $field }}">{{ $label }}</label>
	<div class="tf-auth-input">
		<input id="{{ $prefix }}-{{ $field }}" role="combobox" aria-autocomplete="list" aria-controls="{{ $prefix }}-{{ $field }}-list" autocomplete="off" spellcheck="false" maxlength="100"
			:aria-expanded="comboOpen('{{ $field }}')" :aria-activedescendant="comboActiveId('{{ $field }}')" :aria-invalid="invalid('{{ $field }}')"
			:value="comboValue('{{ $field }}')" :placeholder="comboPlaceholder('{{ $field }}')"
			@focus="openCombo('{{ $field }}')" @click="openCombo('{{ $field }}')" @input="typeCombo('{{ $field }}', $event)" @keydown="comboKey('{{ $field }}', $event)">
		<button type="button" class="tf-combo-toggle" tabindex="-1" aria-hidden="true" @click="toggleCombo('{{ $field }}')">{{ $site->lineIcon('chevronDown', 18) }}</button>
	</div>
	<ul id="{{ $prefix }}-{{ $field }}-list" class="tf-combo-list" role="listbox" aria-label="{{ $label }}" x-show="comboOpen('{{ $field }}')" style="display: none">
		<template x-if="regionsLoading"><li class="tf-combo-note">{{ $words['search']['loading'] }}</li></template>
		<template x-for="(option, i) in comboOptions('{{ $field }}')" :key="option.key">
			<li role="option" :id="comboOptionId('{{ $field }}', i)" :class="comboClass(i)" :aria-selected="comboSelected('{{ $field }}', option)" @mousedown.prevent="pickCombo('{{ $field }}', option)">
				<span x-text="option.label"></span>
				<small x-text="option.hint"></small>
			</li>
		</template>
		<template x-if="comboEmpty('{{ $field }}')"><li class="tf-combo-note">{{ $words['search']['empty'] }}</li></template>
	</ul>
	<template x-if="invalid('{{ $field }}')"><p role="alert" x-text="errorFor('{{ $field }}')"></p></template>
</div>
