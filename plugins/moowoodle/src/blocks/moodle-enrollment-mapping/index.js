import { render, useEffect, useState } from '@wordpress/element';
import {
	RadioControl,
	SelectControl,
	Spinner,
	Notice,
	Disabled,
	ToggleControl,
	ComboboxControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import './tab.scss';
import { SlotFillProvider, Slot, Fill } from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';

const ProTag = ({ isProVersion }) => {
	return !isProVersion ? (
		<span className="pro-tag">{__('Pro', 'moowoodle')}</span>
	) : null;
};

const ProductTab = () => {
	const [linkType, setLinkType] = useState(
		moowoodleProduct.linkType || ''
	);

	const [linkedItemId, setLinkedItemId] = useState(
		String(moowoodleProduct.linkedItemId || '')
	);

	const [options, setOptions] = useState([]);
	const [loading, setLoading] = useState(false);

	useEffect(() => {
		if (!linkType) {
			setOptions([]);
			return;
		}

		setLoading(true);

		const endpoint = 'course' === linkType ? 'courses' : 'cohorts';

		apiFetch({
			path: `/moowoodle/v1/${endpoint}?unlinked_resources_for=${moowoodleProduct.postId}`,
			method: 'GET',
		})
			.then((response) => {
				const formattedOptions = (response.items || []).map((item) => ({
					label: [item.fullname, item.cohort_name]
						.filter(Boolean)
						.join(' || '),

					value: String(item.id),
				}));

				setOptions(formattedOptions);

				if (response.selected_id) {
					setLinkedItemId(String(response.selected_id));
				}
			})
			.catch((error) => {
				console.error('MooWoodle REST API Error:', error);
			})
			.finally(() => {
				setLoading(false);
			});
	}, [linkType]);

	return (
		<SlotFillProvider>
			<Fill name="MooWoodleProductTabFields">
				<div className="options_group">
					<div className="form-field components-radio">
						<label htmlFor="linked_item">
							{__('Link Type', 'moowoodle')}
						</label>

						<RadioControl
							selected={linkType}
							options={[
								{
									label: __('Course', 'moowoodle'),
									value: 'course',
								},
							]}
							onChange={(value) => {
								setLinkType(value);
								setLinkedItemId('');
							}}
						/>

						<Disabled isDisabled={!moowoodleProduct.khali_dabba}>
							<ProTag isProVersion={moowoodleProduct.khali_dabba} />
							<RadioControl
								selected={linkType}
								options={[
									{
										label: __('Cohort', 'moowoodle'),
										value: 'cohort',
									},
								]}
								onChange={(value) => {
									setLinkType(value);
									setLinkedItemId('');
								}}
							/>
						</Disabled>
					</div>

					{loading && (
						<p className="form-field">
							<Spinner />
						</p>
					)}

					{!!linkType && !loading && (
						<p className="form-field">
							<label htmlFor="linked_item">
								{__('Select Item', 'moowoodle')}
							</label>
							<div className="select-item">
								<ComboboxControl
									value={linkedItemId}
									options={options}
									onChange={(value) => {
										setLinkedItemId(value || '');
									}}
									placeholder={__('Search or select an item...', 'moowoodle')}
								/>
							</div>
						</p>
					)}

					<Notice
						status="info"
						isDismissible={false}
						actions={[
							{
								label: __(
									'Synchronize Moodle data',
									'moowoodle'
								),
								url: moowoodleProduct.syncUrl,
								variant: 'link',
							},
						]}
					>
						<p>
							{__(
								"Can't find your course or cohort?",
								'moowoodle'
							)}
						</p>
					</Notice>

				</div>

				<input type="hidden" name="link_type" value={linkType} />
				<input type="hidden" name="linked_item_id" value={linkedItemId} />
			</Fill>
			{applyFilters('moowoodle_product_tab_fields', null)}

			<Slot name="MooWoodleProductTabFields" />
			<input type="hidden" name="product_meta_nonce" value={moowoodleProduct.productMetaNonce} />

		</SlotFillProvider>
	);
};

document.addEventListener('DOMContentLoaded', () => {
	const container = document.getElementById(
		'moodle-enrollment-mapping-tab'
	);

	if (container) {
		render(<ProductTab />, container);
	}
});
