import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const sample=JSON.parse(readFileSync(new URL('../../resources/js/data/riderDeliverySample.json',import.meta.url),'utf8'));
test('sample delivery is explicitly illustrative and contains only public rider-to-delivery points',()=>{
 assert.equal(sample.label,'Sample delivery route');
 assert.match(sample.notice,/Illustration only/);
 assert.match(sample.notice,/not a live rider or an assigned parcel/);
 assert.ok(sample.coordinates.length>2);
 for(const [lat,lon]of sample.coordinates){assert.ok(Number.isFinite(lat)&&lat>=4&&lat<=22);assert.ok(Number.isFinite(lon)&&lon>=116&&lon<=128);}
 assert.notDeepEqual(sample.coordinates[0],sample.coordinates.at(-1));
 assert.doesNotMatch(JSON.stringify(sample),/apiKey|logistics|hub|parcel_id|user_id|token/);
});
