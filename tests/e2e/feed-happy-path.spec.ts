import { test, expect } from '@playwright/test';

test('user can sign up, upload resume, create profile, and see ranked feed', async ({ page, request }) => {
  const email = `e2e+${Date.now()}@example.test`;
  const password = 'password123';

  await page.goto('/register');
  await page.getByLabel('Name').fill('E2E User');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByLabel('Confirm password').fill(password);
  await page.getByRole('button', { name: /register|create account/i }).click();
  await expect(page).toHaveURL(/dashboard|\//);

  // Upload a resume.
  await page.goto('/resumes');
  await page.getByRole('button', { name: 'Upload resume' }).click();
  // UploadDialog uses an unlabelled text input — target it directly.
  await page.locator('.fixed input[type="text"]').fill('Staff frontend');
  await page.setInputFiles('input[type=file]', 'tests/Fixtures/resumes/sample.pdf');
  await page.getByRole('button', { name: 'Upload' }).click();

  // Wait for parse + embed (fake AiClient is instant in testing env).
  await expect(page.getByText(/ready to match/i)).toBeVisible({ timeout: 15000 });

  // Create search profile.
  await page.goto('/search-profiles/create');
  await page.getByLabel('Name').fill('Staff frontend search');
  await page.getByRole('button', { name: /create profile/i }).click();
  await expect(page.getByText('Staff frontend search')).toBeVisible();

  // Seed canonical jobs and recompute matches via the test-only routes.
  await request.post('/testing/seed-jobs');

  // Get the profile ID from the edit link instead.
  await page.goto('/search-profiles');
  const editHref = await page.getByRole('link', { name: 'Staff frontend search' }).getAttribute('href');
  const profileId = editHref?.match(/(\d+)/)?.[1];
  expect(profileId).toBeTruthy();

  await request.post(`/testing/run-matching/${profileId}`);

  // Now view the feed.
  await page.goto(`/feed/${profileId}`);
  await expect(page.locator('article').first()).toBeVisible();
  await expect(page.getByText(/ai rationale pending/i).first()).toBeVisible();

  // Now force a rerank so stage-2 output lands.
  await request.post(`/testing/apply-rerank/${profileId}`);

  // Refresh and confirm stage-2 renders.
  await page.reload();
  await expect(page.locator('article').first()).toBeVisible();

  // Fit score badge is now visible somewhere on the first card.
  await expect(page.locator('article').first().getByText('75', { exact: false })).toBeVisible();

  // Narrative subtitle is rendered (contains the fake text).
  await expect(page.getByText(/Fake career-arc narrative/i).first()).toBeVisible();

  // At least one gap chip.
  await expect(page.getByText('Example requirement').first()).toBeVisible();
});
