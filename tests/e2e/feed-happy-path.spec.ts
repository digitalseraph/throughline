import { test, expect } from '@playwright/test';

test('user can sign up, upload resume, create profile, and see ranked feed', async ({ page, request }) => {
  const email = `e2e+${Date.now()}@example.test`;
  const password = 'password123';

  await page.goto('/register');
  await page.getByLabel('Name').fill('E2E User');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByLabel('Confirm password').fill(password);
  await page.getByRole('button', { name: /register|create account/i }).click();
  await expect(page).toHaveURL(/dashboard|\//);
  await page.waitForLoadState('networkidle');

  // Upload a resume.
  await page.goto('/resumes');
  await page.waitForLoadState('networkidle');
  // Confirm we're on the resumes page (not redirected to /login or /verify-email).
  await expect(page).toHaveURL(/resumes/);
  await expect(page.getByRole('heading', { name: 'Your resumes' })).toBeVisible();
  await page.getByRole('button', { name: 'Upload resume' }).click();
  // UploadDialog uses an unlabelled text input — target it directly.
  await page.locator('.fixed input[type="text"]').fill('Staff frontend');
  await page.setInputFiles('input[type=file]', 'tests/Fixtures/resumes/sample.pdf');
  await page.getByRole('button', { name: 'Upload', exact: true }).click();
  await page.waitForLoadState('networkidle');

  // Wait for parse + embed (fake AiClient is instant in testing env).
  await expect(page.getByText(/ready to match/i)).toBeVisible({ timeout: 5000 });

  // Create search profile.
  await page.goto('/search-profiles/create');
  await page.waitForLoadState('networkidle');
  // ProfileForm uses unlinked labels — target the first text input (the Name field).
  await page.locator('form input[type=text], form input:not([type])').first().fill('Staff frontend search');
  // Link the uploaded resume so run-matching has a resume_id to work with.
  await page.locator('select').first().selectOption({ label: 'Staff frontend' });
  await page.getByRole('button', { name: /create profile/i }).click();
  await expect(page.getByText('Staff frontend search')).toBeVisible();

  // Seed canonical jobs and recompute matches via the test-only routes.
  await request.post('/testing/seed-jobs');

  // Get the profile ID from the edit link instead.
  await page.goto('/search-profiles');
  await page.waitForLoadState('networkidle');
  await expect(page.getByText('Staff frontend search')).toBeVisible();
  const editHref = await page.getByRole('link', { name: 'Staff frontend search' }).getAttribute('href');
  const profileId = editHref?.match(/\/search-profiles\/(\d+)/)?.[1];
  expect(profileId).toBeTruthy();

  await request.post(`/testing/run-matching/${profileId}`);

  // Now view the feed. With sync queue the FeedController triggers rerank inline,
  // so stage-2 data is already populated on the first load.
  await page.goto(`/feed/${profileId}`);
  await page.waitForLoadState('networkidle');
  await expect(page.locator('article').first()).toBeVisible();

  // Force a rerank (idempotent with fake client) to ensure stage-2 data is applied,
  // then reload to confirm it persists.
  await request.post(`/testing/apply-rerank/${profileId}`);
  await page.reload();
  await page.waitForLoadState('networkidle');
  await expect(page.locator('article').first()).toBeVisible();

  // Fit score badge is now visible somewhere on the first card.
  await expect(page.locator('article').first().getByText('75', { exact: false })).toBeVisible();

  // Narrative subtitle is rendered (contains the fake text).
  await expect(page.getByText(/Fake career-arc narrative/i).first()).toBeVisible();

  // At least one gap chip.
  await expect(page.getByText('Example requirement').first()).toBeVisible();

  // Save the first card.
  await page.locator('article').first().getByRole('button', { name: 'Save' }).click();
  await expect(page.locator('article').first().getByText('Saved')).toBeVisible({ timeout: 5000 });

  // Navigate to applications and confirm the saved job shows in the saved column.
  await page.goto('/applications');
  await page.waitForLoadState('networkidle');
  await expect(page.getByRole('heading', { name: 'Applications' })).toBeVisible();

  // The Saved column uses a span label (uppercased via CSS). Find the column wrapper
  // that contains the "Saved" label and assert at least one ApplicationCard link is visible.
  const savedColumn = page.locator('.w-56').filter({ hasText: 'Saved' }).first();
  await expect(savedColumn.locator('a').first()).toBeVisible();

  // Admin smoke: promote the test user to admin via test-only route, then visit /admin.
  await request.post('/testing/promote-by-email', { data: { email } });
  await page.goto('/admin');
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible();
});
